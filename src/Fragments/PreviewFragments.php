<?php

declare(strict_types=1);

namespace Thallo\Render\Fragments;

use Psr\Log\LoggerInterface;
use Thallo\Contracts\Delivery\PreviewSessionVerifier;
use Thallo\Contracts\Delivery\PublicRouteResolver;
use Thallo\Contracts\Preview\PreviewFragmentRenderer;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Render\TwigFactory;

/**
 * The fragment path of the canvas apply (visual builder spec §3.5), behind `render.fragments.
 * enabled`: affected blocks from the operations, minimal roots from the resolver, the
 * verification record checked for every template a root would use, then the roots rendered
 * in isolation. Every doubt — the flag off, a themed session, a resolver that names the whole
 * page, an unverified template, any failure — answers null and the stage refreshes instead.
 */
final class PreviewFragments implements PreviewFragmentRenderer
{
    public function __construct(
        private readonly PublicRouteResolver $resolver,
        private readonly ?PreviewSessionVerifier $sessions,
        private readonly BlockStyleRegistry $registry,
        private readonly TwigFactory $twig,
        private readonly FragmentRenderer $renderer,
        private readonly FragmentVerification $verification,
        private readonly string $theme,
        private readonly bool $enabled,
        private readonly bool $debug = false,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function render(
        string $token,
        array $operations,
        ?array $before,
        array $after,
        array $blockFields,
        ?array $debugChanged = null,
    ): ?array {
        if (!$this->enabled) {
            return null;
        }
        try {
            return $this->attempt($token, $operations, $before, $after, $blockFields, $debugChanged);
        } catch (\Throwable $e) {
            $this->logger?->warning('thallo-render: fragments fell back to the whole page', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param list<array<string,mixed>> $operations
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $after
     * @param list<string> $blockFields
     * @param list<string>|null $debugChanged
     * @return array<string,string>|null
     */
    private function attempt(
        string $token,
        array $operations,
        ?array $before,
        array $after,
        array $blockFields,
        ?array $debugChanged,
    ): ?array {
        $session = $this->sessions?->verify($token);
        if ($session !== null && $session->theme !== null) {
            return null; // only the boot theme's entry template is verified (v1)
        }
        $regionsOf = fn (string $type): array => $this->registry->regionsFor($type);
        $afterIndex = DocumentIndex::of($after, $blockFields, $regionsOf);
        $beforeIndex = $before === null ? null : DocumentIndex::of($before, $blockFields, $regionsOf);
        $affected = AffectedBlocks::derive($operations, $beforeIndex, $afterIndex);
        if ($this->debug && $debugChanged !== null) {
            $this->assertMatches($affected, $debugChanged);
        }
        if ($affected === null) {
            return null;
        }
        $env = $this->twig->environment();
        $dependencies = new TemplateDependencies($env);
        $resolver = new RenderScopeResolver($this->registry, $dependencies);
        $roots = $resolver->resolve($affected, $afterIndex, $beforeIndex);
        if ($roots === null || $roots === []) {
            return null;
        }

        $result = $this->resolver->resolvePreview($token);
        if (($result['kind'] ?? null) !== 'content' || !is_array($result['content'] ?? null)) {
            return null;
        }
        $type = (string) ($result['type'] ?? '');
        $entryTemplate = $type !== '' && $env->getLoader()->exists("entry/{$type}.twig")
            ? "entry/{$type}.twig"
            : 'entry.twig';
        $templates = [];
        foreach ($roots as $root) {
            foreach ($afterIndex->subtree($root) as $member) {
                foreach ($dependencies->templatesFor((string) $afterIndex->typeOf($member)) as $name) {
                    $templates[$name] = true;
                }
            }
        }
        if (!$this->verification->verified($dependencies, $this->theme, $entryTemplate, array_keys($templates))) {
            return null;
        }
        $content = $result['content'];
        $fields = is_array($content['fields'] ?? null) ? $content['fields'] : [];
        $shaped = DocumentIndex::of($fields, $blockFields, $regionsOf);
        return $this->renderer->render(
            $content,
            (string) $result['locale'],
            $shaped,
            $roots,
            $session?->accent,
            $session?->neutral,
        );
    }

    /**
     * Development only: the client's own affected-block list against the derived one.
     *
     * @param list<string>|null $derived
     * @param list<string> $client
     */
    private function assertMatches(?array $derived, array $client): void
    {
        $expected = $derived === null ? null : array_values(array_unique(array_map('strval', $derived)));
        $got = array_values(array_unique(array_map('strval', $client)));
        sort($got);
        if ($expected !== null) {
            sort($expected);
        }
        if ($expected !== $got) {
            $this->logger?->warning('thallo-render: the client\'s affected blocks differ from the derived set', [
                'derived' => $expected,
                'client' => $got,
            ]);
        }
    }
}
