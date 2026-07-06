<?php

declare(strict_types=1);

namespace Thallo\Render\Templates;

use Glueful\Events\Contracts\BaseEvent;

/**
 * A DB template override changed what renders (save, deactivate, restore — spec §5).
 * In-pack event: dispatched by the templates admin controller, consumed by the render
 * purge listener. It may clear same-process loader state as a convenience, but it is
 * NOT the freshness mechanism (that's reset-per-render + version-keyed cache keys).
 */
final class TemplateUpdated extends BaseEvent
{
    public function __construct(
        public readonly string $theme,
        public readonly string $path,
    ) {
        parent::__construct();
    }
}
