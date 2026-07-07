export default defineAppConfig({
  ui: {
    page: {
      slots: {
        root: 'flex flex-col lg:grid lg:grid-cols-10 lg:gap-10',
        left: 'lg:col-span-2',
        center: 'lg:col-span-8',
        right: 'lg:col-span-2 order-first lg:order-last'
      },
      variants: {
        left: {
          true: ''
        },
        right: {
          true: ''
        }
      },
      compoundVariants: [
        {
          left: true,
          right: true,
          class: {
            center: 'lg:col-span-6'
          }
        },
        {
          left: false,
          right: false,
          class: {
            center: 'lg:col-span-10'
          }
        }
      ]
    },
    pageHero: {
      slots: {
        root: 'relative isolate',
        container: 'flex flex-col lg:grid py-24 sm:py-32 lg:py-40 gap-16 sm:gap-y-24',
        wrapper: '',
        header: '',
        headline: 'mb-4',
        title: 'text-5xl sm:text-7xl text-pretty tracking-tight font-bold text-highlighted',
        description: 'text-lg sm:text-xl/8 text-muted',
        body: 'mt-10',
        footer: 'mt-10',
        links: 'flex flex-wrap gap-x-6 gap-y-3'
      },
      variants: {
        orientation: {
          horizontal: {
            container: 'lg:grid-cols-2 lg:items-center',
            description: 'text-pretty'
          },
          vertical: {
            container: '',
            headline: 'justify-center',
            wrapper: 'text-center',
            description: 'text-balance',
            links: 'justify-center'
          }
        },
        reverse: {
          true: {
            wrapper: 'order-last'
          }
        },
        headline: {
          true: {
            headline: 'font-semibold text-primary flex items-center gap-1.5'
          }
        },
        title: {
          true: {
            description: 'mt-6'
          }
        }
      }
    },
    pageAside: {
      slots: {
        root: 'hidden overflow-y-auto lg:block lg:max-h-[calc(100vh-var(--ui-header-height))] lg:sticky lg:top-(--ui-header-height) py-8 lg:ps-4 lg:-ms-4 lg:pe-6.5',
        container: 'relative',
        top: 'sticky -top-8 -mt-8 pointer-events-none z-[1]',
        topHeader: 'h-8 bg-default -mx-4 px-4',
        topBody: 'bg-default relative pointer-events-auto flex flex-col -mx-4 px-4',
        topFooter: 'h-8 bg-gradient-to-b from-default -mx-4 px-4'
      }
    },
    pageCard: {
      slots: {
        root: 'relative flex rounded-lg',
        spotlight: 'absolute inset-0 rounded-[inherit] pointer-events-none bg-default/90',
        container: 'relative flex flex-col flex-1 lg:grid gap-x-8 gap-y-4 p-4 sm:p-6',
        wrapper: 'flex flex-col flex-1 items-start',
        header: 'mb-4',
        body: 'flex-1',
        footer: 'pt-4 mt-auto',
        leading: 'inline-flex items-center mb-2.5',
        leadingIcon: 'size-5 shrink-0 text-primary',
        title: 'text-base text-pretty font-semibold text-highlighted',
        description: 'text-[15px] text-pretty'
      },
      variants: {
        orientation: {
          horizontal: {
            container: 'lg:grid-cols-2 lg:items-center'
          },
          vertical: {
            container: ''
          }
        },
        reverse: {
          true: {
            wrapper: 'order-last'
          }
        },
        variant: {
          solid: {
            root: 'bg-inverted text-inverted',
            title: 'text-inverted',
            description: 'text-dimmed'
          },
          outline: {
            root: 'bg-default ring ring-default',
            description: 'text-muted'
          },
          soft: {
            root: 'bg-elevated/50',
            description: 'text-toned'
          },
          subtle: {
            root: 'bg-elevated/50 ring ring-default',
            description: 'text-toned'
          },
          ghost: {
            description: 'text-muted'
          },
          naked: {
            container: 'p-0 sm:p-0',
            description: 'text-muted'
          }
        },
        to: {
          true: {
            root: [
              'outline-primary/25 has-[>a:focus-visible]:outline-3',
              'transition'
            ]
          }
        },
        title: {
          true: {
            description: 'mt-1'
          }
        },
        highlight: {
          true: {
            root: 'ring-2'
          }
        },
        highlightColor: {
          primary: '',
          secondary: '',
          success: '',
          info: '',
          warning: '',
          error: '',
          neutral: ''
        },
        spotlight: {
          true: {
            root: '[--spotlight-size:400px] before:absolute before:-inset-px before:pointer-events-none before:rounded-[inherit] before:bg-[radial-gradient(var(--spotlight-size)_var(--spotlight-size)_at_calc(var(--spotlight-x,0px))_calc(var(--spotlight-y,0px)),var(--spotlight-color),transparent_70%)]'
          }
        },
        spotlightColor: {
          primary: '',
          secondary: '',
          success: '',
          info: '',
          warning: '',
          error: '',
          neutral: ''
        }
      },
      compoundVariants: [
        {
          variant: 'solid',
          to: true,
          class: {
            root: 'hover:bg-inverted/90'
          }
        },
        {
          variant: 'outline',
          to: true,
          class: {
            root: 'hover:bg-elevated/50'
          }
        },
        {
          variant: 'soft',
          to: true,
          class: {
            root: 'hover:bg-elevated'
          }
        },
        {
          variant: 'subtle',
          to: true,
          class: {
            root: 'hover:bg-elevated'
          }
        },
        {
          variant: 'subtle',
          to: true,
          highlight: false,
          class: {
            root: 'hover:ring-accented'
          }
        },
        {
          variant: [
            'outline',
            'subtle'
          ],
          to: true,
          highlight: false,
          class: {
            root: 'has-[>a:focus-visible]:ring-primary'
          }
        },
        {
          variant: 'ghost',
          to: true,
          class: {
            root: 'hover:bg-elevated/50'
          }
        },
        {
          highlightColor: 'primary',
          highlight: true,
          class: {
            root: 'ring-primary'
          }
        },
        {
          highlightColor: 'neutral',
          highlight: true,
          class: {
            root: 'ring-inverted'
          }
        },
        {
          spotlightColor: 'primary',
          spotlight: true,
          class: {
            root: '[--spotlight-color:var(--ui-primary)]'
          }
        },
        {
          spotlightColor: 'secondary',
          spotlight: true,
          class: {
            root: '[--spotlight-color:var(--ui-secondary)]'
          }
        },
        {
          spotlightColor: 'success',
          spotlight: true,
          class: {
            root: '[--spotlight-color:var(--ui-success)]'
          }
        },
        {
          spotlightColor: 'info',
          spotlight: true,
          class: {
            root: '[--spotlight-color:var(--ui-info)]'
          }
        },
        {
          spotlightColor: 'warning',
          spotlight: true,
          class: {
            root: '[--spotlight-color:var(--ui-warning)]'
          }
        },
        {
          spotlightColor: 'error',
          spotlight: true,
          class: {
            root: '[--spotlight-color:var(--ui-error)]'
          }
        },
        {
          spotlightColor: 'neutral',
          spotlight: true,
          class: {
            root: '[--spotlight-color:var(--ui-bg-inverted)]'
          }
        }
      ],
      defaultVariants: {
        variant: 'outline',
        highlightColor: 'primary',
        spotlightColor: 'primary'
      }
    },
    pageColumns: {
      base: 'relative column-1 md:columns-2 lg:columns-3 gap-8 space-y-8 *:break-inside-avoid-column *:will-change-transform'
    },
    pageCTA: {
      slots: {
        root: 'relative isolate rounded-xl overflow-hidden',
        container: 'flex flex-col lg:grid px-6 py-12 sm:px-12 sm:py-24 lg:px-16 lg:py-24 gap-8 sm:gap-16',
        wrapper: '',
        header: '',
        title: 'text-3xl sm:text-4xl text-pretty tracking-tight font-bold text-highlighted',
        description: 'text-base sm:text-lg text-muted',
        body: 'mt-8',
        footer: 'mt-8',
        links: 'flex flex-wrap gap-x-6 gap-y-3'
      },
      variants: {
        orientation: {
          horizontal: {
            container: 'lg:grid-cols-2 lg:items-center',
            description: 'text-pretty'
          },
          vertical: {
            container: '',
            title: 'text-center',
            description: 'text-center text-balance',
            links: 'justify-center'
          }
        },
        reverse: {
          true: {
            wrapper: 'order-last'
          }
        },
        variant: {
          solid: {
            root: 'bg-inverted text-inverted',
            title: 'text-inverted',
            description: 'text-dimmed'
          },
          outline: {
            root: 'bg-default ring ring-default',
            description: 'text-muted'
          },
          soft: {
            root: 'bg-elevated/50',
            description: 'text-toned'
          },
          subtle: {
            root: 'bg-elevated/50 ring ring-default',
            description: 'text-toned'
          },
          naked: {
            description: 'text-muted'
          }
        },
        title: {
          true: {
            description: 'mt-6'
          }
        }
      },
      defaultVariants: {
        variant: 'outline'
      }
    },
    pageFeature: {
      slots: {
        root: 'relative rounded-sm',
        wrapper: '',
        leading: 'inline-flex items-center justify-center',
        leadingIcon: 'size-5 shrink-0 text-primary',
        title: 'text-base text-pretty font-semibold text-highlighted',
        description: 'text-[15px] text-pretty text-muted'
      },
      variants: {
        orientation: {
          horizontal: {
            root: 'flex items-start gap-2.5',
            leading: 'p-0.5'
          },
          vertical: {
            leading: 'mb-2.5'
          }
        },
        to: {
          true: {
            root: [
              'outline-primary/25 has-focus-visible:outline-3',
              'transition'
            ]
          }
        },
        title: {
          true: {
            description: 'mt-1'
          }
        }
      }
    },
    pageGrid: {
      base: 'relative grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-8'
    },
    pageHeader: {
      slots: {
        root: 'relative border-b border-default py-8',
        container: '',
        wrapper: 'flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4',
        headline: 'mb-2.5 text-sm font-semibold text-primary flex items-center gap-1.5',
        title: 'text-3xl sm:text-4xl text-pretty font-bold text-highlighted',
        description: 'text-lg text-pretty text-muted',
        links: 'flex flex-wrap items-center gap-1.5'
      },
      variants: {
        title: {
          true: {
            description: 'mt-4'
          }
        }
      }
    },
    pageLinks: {
      slots: {
        root: 'flex flex-col gap-3',
        title: 'text-sm font-semibold flex items-center gap-1.5',
        list: 'flex flex-col gap-2',
        item: 'relative',
        link: 'group text-sm flex items-center gap-1.5 rounded-sm outline-primary/25 focus-visible:outline-3',
        linkLeadingIcon: 'size-5 shrink-0',
        linkLabel: 'truncate',
        linkLabelExternalIcon: 'size-3 absolute top-0 text-dimmed'
      },
      variants: {
        active: {
          true: {
            link: 'text-primary font-medium'
          },
          false: {
            link: [
              'text-muted hover:text-default',
              'transition-colors'
            ]
          }
        }
      }
    },
    pageLogos: {
      slots: {
        root: 'relative overflow-hidden',
        title: 'text-lg text-center font-semibold text-highlighted',
        logos: 'mt-10',
        logo: 'size-10 shrink-0'
      },
      variants: {
        marquee: {
          false: {
            logos: 'flex items-center shrink-0 justify-around gap-(--gap) [--gap:--spacing(16)]'
          }
        }
      }
    },
    pageSection: {
      slots: {
        root: 'relative isolate',
        container: 'flex flex-col lg:grid py-16 sm:py-24 lg:py-32 gap-8 sm:gap-16',
        wrapper: '',
        header: '',
        leading: 'flex items-center mb-6',
        leadingIcon: 'size-10 shrink-0 text-primary',
        headline: 'mb-3',
        title: 'text-3xl sm:text-4xl lg:text-5xl text-pretty tracking-tight font-bold text-highlighted',
        description: 'text-base sm:text-lg text-muted',
        body: 'mt-8',
        features: 'grid',
        footer: 'mt-8',
        links: 'flex flex-wrap gap-x-6 gap-y-3'
      },
      variants: {
        orientation: {
          horizontal: {
            container: 'lg:grid-cols-2 lg:items-center',
            description: 'text-pretty',
            features: 'gap-4'
          },
          vertical: {
            container: '',
            headline: 'justify-center',
            leading: 'justify-center',
            title: 'text-center',
            description: 'text-center text-balance',
            links: 'justify-center',
            features: 'sm:grid-cols-2 lg:grid-cols-3 gap-8'
          }
        },
        reverse: {
          true: {
            wrapper: 'order-last'
          }
        },
        headline: {
          true: {
            headline: 'font-semibold text-primary flex items-center gap-1.5'
          }
        },
        title: {
          true: {
            description: 'mt-6'
          }
        },
        description: {
          true: ''
        },
        body: {
          true: ''
        }
      },
      compoundVariants: [
        {
          orientation: 'vertical',
          title: true,
          class: {
            body: 'mt-16'
          }
        },
        {
          orientation: 'vertical',
          description: true,
          class: {
            body: 'mt-16'
          }
        },
        {
          orientation: 'vertical',
          body: true,
          class: {
            footer: 'mt-16'
          }
        }
      ]
    },
    pricingPlan: {
      slots: {
        root: 'relative grid rounded-lg p-6 lg:p-8 xl:p-10 gap-6',
        header: '',
        body: 'flex flex-col min-w-0',
        footer: 'flex flex-col gap-6 items-center',
        titleWrapper: 'flex items-center gap-3',
        title: 'text-highlighted truncate text-2xl sm:text-3xl text-pretty font-semibold',
        description: 'text-muted text-base text-pretty mt-2',
        priceWrapper: 'flex items-center gap-1',
        price: 'text-highlighted text-3xl sm:text-4xl font-semibold',
        discount: 'text-muted line-through text-xl sm:text-2xl',
        billing: 'flex flex-col justify-between min-w-0',
        billingPeriod: 'text-toned truncate text-xs font-medium',
        billingCycle: 'text-muted truncate text-xs font-medium',
        features: 'flex flex-col gap-3 flex-1 mt-6 grow-0',
        feature: 'flex items-center gap-2 min-w-0',
        featureIcon: 'size-5 shrink-0 text-primary',
        featureTitle: 'text-muted text-sm truncate',
        badge: '',
        button: '',
        tagline: 'text-base font-semibold text-default',
        terms: 'text-xs/5 text-muted text-center text-balance'
      },
      variants: {
        orientation: {
          horizontal: {
            root: 'grid-cols-1 lg:grid-cols-3 justify-between divide-y lg:divide-y-0 lg:divide-x divide-default',
            body: 'lg:col-span-2 pb-6 lg:pb-0 lg:pr-6 justify-center',
            footer: 'lg:justify-center lg:items-center lg:p-6 lg:max-w-xs lg:w-full lg:mx-auto',
            features: 'lg:grid lg:grid-cols-2 lg:mt-12'
          },
          vertical: {
            footer: 'justify-end',
            priceWrapper: 'mt-6'
          }
        },
        variant: {
          solid: {
            root: 'bg-inverted',
            title: 'text-inverted',
            description: 'text-dimmed',
            price: 'text-inverted',
            discount: 'text-dimmed',
            billingCycle: 'text-dimmed',
            billingPeriod: 'text-dimmed',
            featureTitle: 'text-dimmed'
          },
          outline: {
            root: 'bg-default ring ring-default'
          },
          soft: {
            root: 'bg-elevated/50'
          },
          subtle: {
            root: 'bg-elevated/50 ring ring-default'
          }
        },
        highlight: {
          true: {
            root: 'ring-2 ring-inset ring-primary'
          }
        },
        scale: {
          true: {
            root: 'lg:scale-[1.1] lg:z-[1]'
          }
        }
      },
      compoundVariants: [
        {
          orientation: 'horizontal',
          variant: 'soft',
          class: {
            root: 'divide-accented'
          }
        },
        {
          orientation: 'horizontal',
          variant: 'subtle',
          class: {
            root: 'divide-accented'
          }
        }
      ],
      defaultVariants: {
        variant: 'outline'
      }
    },
    pricingPlans: {
      base: 'flex flex-col gap-y-8',
      variants: {
        orientation: {
          horizontal: 'lg:grid lg:grid-cols-[repeat(var(--count),minmax(0,1fr))]',
          vertical: ''
        },
        compact: {
          false: 'gap-x-8'
        },
        scale: {
          true: ''
        }
      },
      compoundVariants: [
        {
          compact: false,
          scale: true,
          class: 'lg:gap-x-13'
        }
      ]
    },
    pricingTable: {
      slots: {
        root: 'w-full relative',
        table: 'w-full table-fixed border-separate border-spacing-x-0 hidden md:table h-fit',
        list: 'md:hidden flex flex-col gap-6 w-full',
        item: 'p-6 flex flex-col border border-default rounded-lg',
        caption: 'sr-only',
        thead: '',
        tbody: '',
        tr: '',
        th: 'py-4 font-normal text-left rtl:text-right border-b border-default',
        td: 'px-6 py-4 text-center border-b border-default',
        tier: 'p-6 text-left rtl:text-right font-normal align-top h-full',
        tierWrapper: 'flex flex-col md:h-full',
        tierTitleWrapper: 'flex items-center gap-3',
        tierTitle: 'text-lg font-semibold text-highlighted',
        tierDescription: 'text-sm font-normal text-muted mt-1',
        tierBadge: 'truncate',
        tierPriceWrapper: 'flex items-center gap-1 mt-4',
        tierPrice: 'text-highlighted text-3xl sm:text-4xl font-semibold',
        tierDiscount: 'text-muted line-through text-xl sm:text-2xl',
        tierBilling: 'flex flex-col justify-between min-w-0',
        tierBillingPeriod: 'text-toned truncate text-xs font-medium',
        tierBillingCycle: 'text-muted truncate text-xs font-medium',
        tierButton: 'mt-6 md:mt-auto md:pt-6',
        tierFeatureIcon: 'size-5 shrink-0',
        section: 'mt-6 flex flex-col gap-2',
        sectionTitle: 'font-semibold text-sm text-highlighted',
        feature: 'flex items-center justify-between gap-1',
        featureTitle: 'text-sm text-default',
        featureValue: 'text-sm text-muted flex justify-center min-w-5'
      },
      variants: {
        section: {
          true: {
            tr: '*:pt-8'
          }
        },
        active: {
          true: {
            tierFeatureIcon: 'text-primary'
          }
        },
        highlight: {
          true: {
            tier: 'bg-elevated/50 border-x border-t border-default rounded-t-lg',
            td: 'bg-elevated/50 border-x border-default',
            item: 'bg-elevated/50'
          }
        }
      }
    },
    navigationMenu: {
      slots: {
        root: 'relative flex gap-1.5 [&>div]:min-w-0',
        list: 'isolate min-w-0',
        label: 'w-full flex items-center gap-1.5 font-semibold text-xs/5 text-highlighted px-2.5 py-1.5',
        item: 'min-w-0',
        link: 'group relative w-full flex items-center gap-1.5 font-medium text-sm before:absolute before:z-[-1] before:rounded-md focus:outline-none focus-visible:outline-none focus-visible:before:outline-3',
        linkLeadingIcon: 'shrink-0 size-5',
        linkLeadingAvatar: 'shrink-0',
        linkLeadingAvatarSize: '2xs',
        linkLeadingChipSize: 'sm',
        linkTrailing: 'group ms-auto inline-flex gap-1.5 items-center',
        linkTrailingBadge: 'shrink-0',
        linkTrailingBadgeSize: 'sm',
        linkTrailingIcon: 'size-5 transform shrink-0 group-data-[state=open]:rotate-180 transition-transform duration-200',
        linkLabel: 'truncate',
        linkLabelExternalIcon: 'inline-block size-3 align-top text-dimmed',
        childList: 'isolate',
        childLabel: 'text-xs text-highlighted',
        childItem: '',
        childLink: 'group relative size-full flex items-start text-start text-sm before:absolute before:z-[-1] before:rounded-md focus:outline-none focus-visible:outline-none focus-visible:before:outline-3',
        childLinkWrapper: 'min-w-0',
        childLinkIcon: 'size-5 shrink-0',
        childLinkLabel: 'truncate',
        childLinkLabelExternalIcon: 'inline-block size-3 align-top text-dimmed',
        childLinkDescription: 'text-muted',
        separator: 'px-2 h-px bg-border',
        viewportWrapper: 'absolute top-full left-0 flex w-full',
        viewport: 'relative overflow-hidden bg-default shadow-lg rounded-md ring ring-default h-(--reka-navigation-menu-viewport-height) w-full transition-[width,height,left,right] duration-200 origin-[top_center] data-[state=open]:animate-[scale-in_100ms_ease-out] data-[state=closed]:animate-[scale-out_100ms_ease-in] z-1',
        content: '',
        indicator: 'absolute left-0 data-[state=visible]:animate-[fade-in_100ms_ease-out] data-[state=hidden]:animate-[fade-out_100ms_ease-in] data-[state=hidden]:opacity-0 bottom-0 z-2 w-(--reka-navigation-menu-indicator-size) translate-x-(--reka-navigation-menu-indicator-position) flex h-2.5 items-end justify-center overflow-hidden transition-[translate,width] duration-200',
        arrow: 'relative top-[50%] size-2.5 rotate-45 border border-default bg-default z-1 rounded-xs'
      },
      variants: {
        color: {
          primary: {
            link: 'before:outline-primary/25',
            childLink: 'before:outline-primary/25'
          },
          secondary: {
            link: 'before:outline-secondary/25',
            childLink: 'before:outline-secondary/25'
          },
          success: {
            link: 'before:outline-success/25',
            childLink: 'before:outline-success/25'
          },
          info: {
            link: 'before:outline-info/25',
            childLink: 'before:outline-info/25'
          },
          warning: {
            link: 'before:outline-warning/25',
            childLink: 'before:outline-warning/25'
          },
          error: {
            link: 'before:outline-error/25',
            childLink: 'before:outline-error/25'
          },
          neutral: {
            link: 'before:outline-inverted/25',
            childLink: 'before:outline-inverted/25'
          }
        },
        highlightColor: {
          primary: '',
          secondary: '',
          success: '',
          info: '',
          warning: '',
          error: '',
          neutral: ''
        },
        variant: {
          pill: '',
          link: ''
        },
        orientation: {
          horizontal: {
            root: 'items-center justify-between',
            list: 'flex items-center',
            item: 'py-2',
            link: 'px-2.5 py-1.5 before:inset-x-px before:inset-y-0',
            childList: 'grid p-2',
            childLink: 'px-3 py-2 gap-2 before:inset-x-px before:inset-y-0',
            childLinkLabel: 'font-medium',
            content: 'absolute top-0 left-0 w-full max-h-[70vh] overflow-y-auto'
          },
          vertical: {
            root: 'flex-col',
            link: 'flex-row px-2.5 py-1.5 before:inset-y-px before:inset-x-0',
            childLabel: 'px-1.5 py-0.5',
            childLink: 'p-1.5 gap-1.5 before:inset-y-px before:inset-x-0'
          }
        },
        contentOrientation: {
          horizontal: {
            viewportWrapper: 'justify-center',
            content: 'data-[motion=from-start]:animate-[enter-from-left_200ms_ease] data-[motion=from-end]:animate-[enter-from-right_200ms_ease] data-[motion=to-start]:animate-[exit-to-left_200ms_ease] data-[motion=to-end]:animate-[exit-to-right_200ms_ease]'
          },
          vertical: {
            viewport: 'sm:w-(--reka-navigation-menu-viewport-width) left-(--reka-navigation-menu-viewport-left) rtl:left-auto rtl:right-[calc(100%-var(--reka-navigation-menu-viewport-left)-var(--reka-navigation-menu-viewport-width))]'
          }
        },
        active: {
          true: {
            childLink: 'before:bg-elevated text-highlighted',
            childLinkIcon: 'text-default'
          },
          false: {
            link: 'text-muted',
            linkLeadingIcon: 'text-dimmed',
            childLink: [
              'hover:before:bg-elevated/50 text-default hover:text-highlighted',
              'transition-colors before:transition-colors'
            ],
            childLinkIcon: [
              'text-dimmed group-hover:text-default',
              'transition-colors'
            ]
          }
        },
        disabled: {
          true: {
            link: 'cursor-not-allowed opacity-75'
          }
        },
        highlight: {
          true: ''
        },
        level: {
          true: ''
        },
        collapsed: {
          true: ''
        }
      },
      compoundVariants: [
        {
          orientation: 'horizontal',
          contentOrientation: 'horizontal',
          class: {
            childList: 'grid-cols-2 gap-2'
          }
        },
        {
          orientation: 'horizontal',
          contentOrientation: 'vertical',
          class: {
            childList: 'gap-1',
            content: 'w-60'
          }
        },
        {
          orientation: 'vertical',
          collapsed: false,
          class: {
            childList: 'ms-5 border-s border-default',
            childItem: 'ps-1.5 -ms-px',
            content: 'data-[state=open]:animate-[collapsible-down_200ms_ease-out] data-[state=closed]:animate-[collapsible-up_200ms_ease-out] data-[state=closed]:overflow-hidden'
          }
        },
        {
          orientation: 'vertical',
          collapsed: true,
          class: {
            link: 'px-1.5',
            linkLabel: 'hidden',
            linkTrailing: 'hidden',
            content: 'shadow-sm rounded-sm min-h-6 p-1'
          }
        },
        {
          orientation: 'horizontal',
          highlight: true,
          class: {
            link: [
              'after:absolute after:-bottom-2 after:inset-x-2.5 after:block after:h-px after:rounded-full',
              'after:transition-colors'
            ]
          }
        },
        {
          orientation: 'vertical',
          highlight: true,
          level: true,
          class: {
            link: [
              'after:absolute after:-start-1.5 after:inset-y-0.5 after:block after:w-px after:rounded-full',
              'after:transition-colors'
            ]
          }
        },
        {
          disabled: false,
          active: false,
          variant: 'pill',
          class: {
            link: [
              'hover:text-highlighted hover:before:bg-elevated/50',
              'transition-colors before:transition-colors'
            ],
            linkLeadingIcon: [
              'group-hover:text-default',
              'transition-colors'
            ]
          }
        },
        {
          disabled: false,
          active: false,
          variant: 'pill',
          orientation: 'horizontal',
          class: {
            link: 'data-[state=open]:text-highlighted',
            linkLeadingIcon: 'group-data-[state=open]:text-default'
          }
        },
        {
          disabled: false,
          variant: 'pill',
          highlight: true,
          orientation: 'horizontal',
          class: {
            link: 'data-[state=open]:before:bg-elevated/50'
          }
        },
        {
          disabled: false,
          variant: 'pill',
          highlight: false,
          active: false,
          orientation: 'horizontal',
          class: {
            link: 'data-[state=open]:before:bg-elevated/50'
          }
        },
        {
          color: 'primary',
          variant: 'pill',
          active: true,
          class: {
            link: 'text-primary',
            linkLeadingIcon: 'text-primary group-data-[state=open]:text-primary'
          }
        },
        {
          color: 'neutral',
          variant: 'pill',
          active: true,
          class: {
            link: 'text-highlighted',
            linkLeadingIcon: 'text-highlighted group-data-[state=open]:text-highlighted'
          }
        },
        {
          variant: 'pill',
          active: true,
          highlight: false,
          class: {
            link: 'before:bg-elevated'
          }
        },
        {
          variant: 'pill',
          active: true,
          highlight: true,
          disabled: false,
          class: {
            link: [
              'hover:before:bg-elevated/50',
              'before:transition-colors'
            ]
          }
        },
        {
          disabled: false,
          active: false,
          variant: 'link',
          class: {
            link: [
              'hover:text-highlighted',
              'transition-colors'
            ],
            linkLeadingIcon: [
              'group-hover:text-default',
              'transition-colors'
            ]
          }
        },
        {
          disabled: false,
          active: false,
          variant: 'link',
          orientation: 'horizontal',
          class: {
            link: 'data-[state=open]:text-highlighted',
            linkLeadingIcon: 'group-data-[state=open]:text-default'
          }
        },
        {
          color: 'primary',
          variant: 'link',
          active: true,
          class: {
            link: 'text-primary',
            linkLeadingIcon: 'text-primary group-data-[state=open]:text-primary'
          }
        },
        {
          color: 'neutral',
          variant: 'link',
          active: true,
          class: {
            link: 'text-highlighted',
            linkLeadingIcon: 'text-highlighted group-data-[state=open]:text-highlighted'
          }
        },
        {
          highlightColor: 'primary',
          highlight: true,
          level: true,
          active: true,
          class: {
            link: 'after:bg-primary'
          }
        },
        {
          highlightColor: 'neutral',
          highlight: true,
          level: true,
          active: true,
          class: {
            link: 'after:bg-inverted'
          }
        }
      ],
      defaultVariants: {
        color: 'primary',
        highlightColor: 'primary',
        variant: 'pill'
      }
    },
    pagination: {
      slots: {
        root: '',
        list: 'flex items-center gap-1',
        ellipsis: 'pointer-events-none',
        label: 'min-w-5 text-center',
        first: '',
        prev: '',
        item: '',
        next: '',
        last: ''
      }
    },
    tabs: {
      slots: {
        root: 'flex items-center gap-2',
        list: 'relative flex p-1 group',
        indicator: 'absolute transition-[translate,width] duration-200',
        trigger: [
          'group relative inline-flex items-center min-w-0 data-[state=inactive]:text-muted hover:data-[state=inactive]:not-disabled:text-default font-medium rounded-md disabled:cursor-not-allowed disabled:opacity-75',
          'transition-colors'
        ],
        leadingIcon: 'shrink-0',
        leadingAvatar: 'shrink-0',
        leadingAvatarSize: '',
        label: 'truncate',
        trailingBadge: 'shrink-0',
        trailingBadgeSize: 'sm',
        content: 'w-full rounded-md focus-visible:outline-3'
      },
      variants: {
        color: {
          primary: {
            content: 'outline-primary/25'
          },
          secondary: {
            content: 'outline-secondary/25'
          },
          success: {
            content: 'outline-success/25'
          },
          info: {
            content: 'outline-info/25'
          },
          warning: {
            content: 'outline-warning/25'
          },
          error: {
            content: 'outline-error/25'
          },
          neutral: {
            content: 'outline-inverted/25'
          }
        },
        variant: {
          pill: {
            list: 'bg-elevated rounded-lg',
            trigger: [
              'grow',
              "in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:before:content-[''] in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:before:absolute in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:before:inset-0 in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:before:rounded-md in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:before:shadow-xs in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:before:-z-10 in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:isolate"
            ],
            indicator: 'rounded-md shadow-xs'
          },
          link: {
            list: 'border-default',
            indicator: 'rounded-full',
            trigger: "in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:content-[''] in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:absolute in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:rounded-full"
          }
        },
        orientation: {
          horizontal: {
            root: 'flex-col',
            list: 'w-full',
            indicator: 'left-0 w-(--reka-tabs-indicator-size) translate-x-(--reka-tabs-indicator-position)',
            trigger: 'justify-center'
          },
          vertical: {
            list: 'flex-col',
            indicator: 'top-0 h-(--reka-tabs-indicator-size) translate-y-(--reka-tabs-indicator-position)'
          }
        },
        size: {
          xs: {
            trigger: 'px-2 py-1 text-xs gap-1',
            leadingIcon: 'size-4',
            leadingAvatarSize: '3xs'
          },
          sm: {
            trigger: 'px-2.5 py-1.5 text-xs gap-1.5',
            leadingIcon: 'size-4',
            leadingAvatarSize: '3xs'
          },
          md: {
            trigger: 'px-3 py-1.5 text-sm gap-1.5',
            leadingIcon: 'size-5',
            leadingAvatarSize: '2xs'
          },
          lg: {
            trigger: 'px-3 py-2 text-sm gap-2',
            leadingIcon: 'size-5',
            leadingAvatarSize: '2xs'
          },
          xl: {
            trigger: 'px-3 py-2 text-base gap-2',
            leadingIcon: 'size-6',
            leadingAvatarSize: 'xs'
          }
        }
      },
      compoundVariants: [
        {
          orientation: 'horizontal',
          variant: 'pill',
          class: {
            indicator: 'inset-y-1'
          }
        },
        {
          orientation: 'horizontal',
          variant: 'link',
          class: {
            list: 'border-b -mb-px',
            indicator: '-bottom-px h-px',
            trigger: 'in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:inset-x-0 in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:-bottom-[calc(var(--spacing)+1px)] in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:h-px'
          }
        },
        {
          orientation: 'vertical',
          variant: 'pill',
          class: {
            indicator: 'inset-x-1',
            list: 'items-center',
            trigger: 'w-full justify-center'
          }
        },
        {
          orientation: 'vertical',
          variant: 'link',
          class: {
            list: 'border-s -ms-px',
            indicator: '-start-px w-px',
            trigger: 'in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:inset-y-0 in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:-start-[calc(var(--spacing)+1px)] in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:w-px'
          }
        },
        {
          color: 'primary',
          variant: 'pill',
          class: {
            indicator: 'bg-primary',
            trigger: [
              'data-[state=active]:text-inverted outline-primary/25 focus-visible:outline-3',
              'in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:before:bg-primary'
            ]
          }
        },
        {
          color: 'neutral',
          variant: 'pill',
          class: {
            indicator: 'bg-inverted',
            trigger: [
              'data-[state=active]:text-inverted outline-inverted/25 focus-visible:outline-3',
              'in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:before:bg-inverted'
            ]
          }
        },
        {
          color: 'primary',
          variant: 'link',
          class: {
            indicator: 'bg-primary',
            trigger: [
              'data-[state=active]:text-primary outline-primary/25 focus-visible:outline-3',
              'in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:bg-primary'
            ]
          }
        },
        {
          color: 'neutral',
          variant: 'link',
          class: {
            indicator: 'bg-inverted',
            trigger: [
              'data-[state=active]:text-highlighted outline-inverted/25 focus-visible:outline-3',
              'in-[[data-slot=list]:not(:has([data-slot=indicator]))]:data-[state=active]:after:bg-inverted'
            ]
          }
        }
      ],
      defaultVariants: {
        color: 'primary',
        variant: 'pill',
        size: 'md'
      }
    },
    accordion: {
      slots: {
        root: 'w-full',
        item: 'border-b border-default last:border-b-0',
        header: 'flex',
        trigger: 'group flex-1 flex items-center gap-1.5 font-medium text-sm py-3.5 outline-primary/25 focus-visible:outline-3 min-w-0 rounded-md',
        content: 'data-[state=open]:animate-[accordion-down_200ms_ease-out] data-[state=closed]:animate-[accordion-up_200ms_ease-out] data-[state=closed]:overflow-hidden focus:outline-none',
        body: 'text-sm pb-3.5',
        leadingIcon: 'shrink-0 size-5',
        trailingIcon: 'shrink-0 size-5 ms-auto group-data-[state=open]:rotate-180 transition-transform duration-200',
        label: 'text-start break-words'
      },
      variants: {
        disabled: {
          true: {
            trigger: 'cursor-not-allowed opacity-75'
          }
        }
      }
    },
    stepper: {
      slots: {
        root: 'flex gap-4',
        header: 'flex',
        item: 'group text-center relative w-full',
        container: 'relative',
        trigger: 'rounded-full font-medium text-center align-middle flex items-center justify-center font-semibold group-data-[state=completed]:text-inverted group-data-[state=active]:text-inverted text-muted bg-elevated focus-visible:outline-3',
        indicator: 'flex items-center justify-center size-full',
        icon: 'shrink-0',
        separator: 'absolute rounded-full group-data-[disabled]:opacity-75 bg-accented',
        wrapper: '',
        title: 'font-medium text-default',
        description: 'text-muted text-wrap',
        content: 'size-full'
      },
      variants: {
        orientation: {
          horizontal: {
            root: 'flex-col',
            container: 'flex justify-center',
            separator: 'top-[calc(50%-2px)] h-0.5',
            wrapper: 'mt-1'
          },
          vertical: {
            header: 'flex-col gap-4',
            item: 'flex text-start',
            separator: 'start-[calc(50%-1px)] -bottom-[10px] w-0.5'
          }
        },
        size: {
          xs: {
            trigger: 'size-6 text-xs',
            icon: 'size-3',
            title: 'text-xs',
            description: 'text-xs',
            wrapper: 'mt-1.5'
          },
          sm: {
            trigger: 'size-8 text-sm',
            icon: 'size-4',
            title: 'text-xs',
            description: 'text-xs',
            wrapper: 'mt-2'
          },
          md: {
            trigger: 'size-10 text-base',
            icon: 'size-5',
            title: 'text-sm',
            description: 'text-sm',
            wrapper: 'mt-2.5'
          },
          lg: {
            trigger: 'size-12 text-lg',
            icon: 'size-6',
            title: 'text-base',
            description: 'text-base',
            wrapper: 'mt-3'
          },
          xl: {
            trigger: 'size-14 text-xl',
            icon: 'size-7',
            title: 'text-lg',
            description: 'text-lg',
            wrapper: 'mt-3.5'
          }
        },
        color: {
          primary: {
            trigger: 'group-data-[state=completed]:bg-primary group-data-[state=active]:bg-primary outline-primary/25',
            separator: 'group-data-[state=completed]:bg-primary'
          },
          secondary: {
            trigger: 'group-data-[state=completed]:bg-secondary group-data-[state=active]:bg-secondary outline-secondary/25',
            separator: 'group-data-[state=completed]:bg-secondary'
          },
          success: {
            trigger: 'group-data-[state=completed]:bg-success group-data-[state=active]:bg-success outline-success/25',
            separator: 'group-data-[state=completed]:bg-success'
          },
          info: {
            trigger: 'group-data-[state=completed]:bg-info group-data-[state=active]:bg-info outline-info/25',
            separator: 'group-data-[state=completed]:bg-info'
          },
          warning: {
            trigger: 'group-data-[state=completed]:bg-warning group-data-[state=active]:bg-warning outline-warning/25',
            separator: 'group-data-[state=completed]:bg-warning'
          },
          error: {
            trigger: 'group-data-[state=completed]:bg-error group-data-[state=active]:bg-error outline-error/25',
            separator: 'group-data-[state=completed]:bg-error'
          },
          neutral: {
            trigger: 'group-data-[state=completed]:bg-inverted group-data-[state=active]:bg-inverted outline-inverted/25',
            separator: 'group-data-[state=completed]:bg-inverted'
          }
        }
      },
      compoundVariants: [
        {
          orientation: 'horizontal',
          size: 'xs',
          class: {
            separator: 'start-[calc(50%+16px)] end-[calc(-50%+16px)]'
          }
        },
        {
          orientation: 'horizontal',
          size: 'sm',
          class: {
            separator: 'start-[calc(50%+20px)] end-[calc(-50%+20px)]'
          }
        },
        {
          orientation: 'horizontal',
          size: 'md',
          class: {
            separator: 'start-[calc(50%+28px)] end-[calc(-50%+28px)]'
          }
        },
        {
          orientation: 'horizontal',
          size: 'lg',
          class: {
            separator: 'start-[calc(50%+32px)] end-[calc(-50%+32px)]'
          }
        },
        {
          orientation: 'horizontal',
          size: 'xl',
          class: {
            separator: 'start-[calc(50%+36px)] end-[calc(-50%+36px)]'
          }
        },
        {
          orientation: 'vertical',
          size: 'xs',
          class: {
            separator: 'top-[30px]',
            item: 'gap-1.5'
          }
        },
        {
          orientation: 'vertical',
          size: 'sm',
          class: {
            separator: 'top-[38px]',
            item: 'gap-2'
          }
        },
        {
          orientation: 'vertical',
          size: 'md',
          class: {
            separator: 'top-[46px]',
            item: 'gap-2.5'
          }
        },
        {
          orientation: 'vertical',
          size: 'lg',
          class: {
            separator: 'top-[54px]',
            item: 'gap-3'
          }
        },
        {
          orientation: 'vertical',
          size: 'xl',
          class: {
            separator: 'top-[62px]',
            item: 'gap-3.5'
          }
        }
      ],
      defaultVariants: {
        size: 'md',
        color: 'primary'
      }
    },
    blogPost: {
      slots: {
        root: 'relative group/blog-post flex flex-col rounded-lg overflow-hidden',
        header: 'relative overflow-hidden aspect-[16/9] w-full pointer-events-none',
        body: 'min-w-0 flex-1 flex flex-col',
        footer: '',
        image: 'object-cover object-top w-full h-full',
        title: 'text-xl text-pretty font-semibold text-highlighted',
        description: 'mt-1 text-base text-pretty',
        authors: 'pt-4 mt-auto flex flex-wrap gap-x-3 gap-y-1.5',
        avatar: '',
        meta: 'flex items-center gap-2 mb-2',
        date: 'text-sm',
        badge: ''
      },
      variants: {
        orientation: {
          horizontal: {
            root: 'lg:grid lg:grid-cols-2 lg:items-center gap-x-8',
            body: 'justify-center p-4 sm:p-6 lg:px-0'
          },
          vertical: {
            root: 'flex flex-col',
            body: 'p-4 sm:p-6'
          }
        },
        variant: {
          outline: {
            root: 'bg-default ring ring-default',
            date: 'text-toned',
            description: 'text-muted'
          },
          soft: {
            root: 'bg-elevated/50',
            date: 'text-muted',
            description: 'text-toned'
          },
          subtle: {
            root: 'bg-elevated/50 ring ring-default',
            date: 'text-muted',
            description: 'text-toned'
          },
          ghost: {
            date: 'text-toned',
            description: 'text-muted',
            header: 'shadow-lg rounded-lg'
          },
          naked: {
            root: 'p-0 sm:p-0',
            date: 'text-toned',
            description: 'text-muted',
            header: 'shadow-lg rounded-lg'
          }
        },
        to: {
          true: {
            root: [
              'outline-primary/25 has-[>a:focus-visible]:outline-3',
              'transition'
            ],
            image: 'transform transition-transform duration-200 group-hover/blog-post:scale-110',
            avatar: 'inline-flex transform transition-transform duration-200 hover:scale-115 rounded-full outline-primary/25 focus-visible:outline-3'
          }
        },
        image: {
          true: ''
        }
      },
      compoundVariants: [
        {
          variant: 'outline',
          to: true,
          class: {
            root: 'hover:bg-elevated/50'
          }
        },
        {
          variant: 'soft',
          to: true,
          class: {
            root: 'hover:bg-elevated'
          }
        },
        {
          variant: 'subtle',
          to: true,
          class: {
            root: 'hover:bg-elevated hover:ring-accented'
          }
        },
        {
          variant: [
            'outline',
            'subtle'
          ],
          to: true,
          class: {
            root: 'has-[>a:focus-visible]:ring-primary'
          }
        },
        {
          variant: 'ghost',
          to: true,
          class: {
            root: 'hover:bg-elevated/50',
            header: [
              'group-hover/blog-post:shadow-none',
              'transition-all'
            ]
          }
        },
        {
          variant: 'ghost',
          to: true,
          orientation: 'vertical',
          class: {
            header: 'group-hover/blog-post:rounded-b-none'
          }
        },
        {
          variant: 'ghost',
          to: true,
          orientation: 'horizontal',
          class: {
            header: 'group-hover/blog-post:rounded-r-none'
          }
        },
        {
          orientation: 'vertical',
          image: false,
          variant: 'naked',
          class: {
            body: 'p-0 sm:p-0'
          }
        }
      ],
      defaultVariants: {
        variant: 'outline'
      }
    },
    blogPosts: {
      base: 'flex flex-col gap-8 lg:gap-y-16',
      variants: {
        orientation: {
          horizontal: 'sm:grid sm:grid-cols-2 lg:grid-cols-3',
          vertical: ''
        }
      }
    },
    footerColumns: {
          slots: {
            root: 'xl:grid xl:grid-cols-3 xl:gap-8',
            left: 'mb-10 xl:mb-0',
            center: 'flex flex-col lg:grid grid-flow-col auto-cols-fr gap-8 xl:col-span-2',
            right: 'mt-10 xl:mt-0',
            label: 'text-sm font-semibold',
            list: 'mt-6 space-y-4',
            item: 'relative',
            link: 'group text-sm flex items-center gap-1.5 rounded-sm outline-primary/25 focus-visible:outline-3',
            linkLeadingIcon: 'size-5 shrink-0',
            linkLabel: 'truncate',
            linkLabelExternalIcon: 'size-3 absolute top-0 text-dimmed inline-block'
          },
          variants: {
            active: {
              true: {
                link: 'text-primary font-medium'
              },
              false: {
                link: [
                  'text-muted hover:text-default',
                  'transition-colors'
                ]
              }
            }
          }
        },
    separator: {
          slots: {
            root: 'flex items-center align-center text-center',
            border: '',
            container: 'font-medium text-default flex',
            icon: 'shrink-0 size-5',
            avatar: 'shrink-0',
            avatarSize: '2xs',
            label: 'text-sm'
          },
          variants: {
            color: {
              primary: {
                border: 'border-primary'
              },
              secondary: {
                border: 'border-secondary'
              },
              success: {
                border: 'border-success'
              },
              info: {
                border: 'border-info'
              },
              warning: {
                border: 'border-warning'
              },
              error: {
                border: 'border-error'
              },
              neutral: {
                border: 'border-default'
              }
            },
            orientation: {
              horizontal: {
                root: 'w-full flex-row',
                border: 'w-full',
                container: 'whitespace-nowrap'
              },
              vertical: {
                root: 'h-full flex-col',
                border: 'h-full',
                container: ''
              }
            },
            size: {
              xs: '',
              sm: '',
              md: '',
              lg: '',
              xl: ''
            },
            position: {
              start: '',
              center: '',
              end: ''
            },
            type: {
              solid: {
                border: 'border-solid'
              },
              dashed: {
                border: 'border-dashed'
              },
              dotted: {
                border: 'border-dotted'
              }
            }
          },
          compoundVariants: [
            {
              orientation: 'horizontal',
              position: 'start',
              class: {
                container: 'me-3'
              }
            },
            {
              orientation: 'horizontal',
              position: 'center',
              class: {
                container: 'mx-3'
              }
            },
            {
              orientation: 'horizontal',
              position: 'end',
              class: {
                container: 'ms-3'
              }
            },
            {
              orientation: 'vertical',
              position: 'start',
              class: {
                container: 'mb-2'
              }
            },
            {
              orientation: 'vertical',
              position: 'center',
              class: {
                container: 'my-2'
              }
            },
            {
              orientation: 'vertical',
              position: 'end',
              class: {
                container: 'mt-2'
              }
            },
            {
              orientation: 'horizontal',
              size: 'xs',
              class: {
                border: 'border-t'
              }
            },
            {
              orientation: 'horizontal',
              size: 'sm',
              class: {
                border: 'border-t-[2px]'
              }
            },
            {
              orientation: 'horizontal',
              size: 'md',
              class: {
                border: 'border-t-[3px]'
              }
            },
            {
              orientation: 'horizontal',
              size: 'lg',
              class: {
                border: 'border-t-[4px]'
              }
            },
            {
              orientation: 'horizontal',
              size: 'xl',
              class: {
                border: 'border-t-[5px]'
              }
            },
            {
              orientation: 'vertical',
              size: 'xs',
              class: {
                border: 'border-s'
              }
            },
            {
              orientation: 'vertical',
              size: 'sm',
              class: {
                border: 'border-s-[2px]'
              }
            },
            {
              orientation: 'vertical',
              size: 'md',
              class: {
                border: 'border-s-[3px]'
              }
            },
            {
              orientation: 'vertical',
              size: 'lg',
              class: {
                border: 'border-s-[4px]'
              }
            },
            {
              orientation: 'vertical',
              size: 'xl',
              class: {
                border: 'border-s-[5px]'
              }
            }
          ],
          defaultVariants: {
            color: 'neutral',
            size: 'xs',
            type: 'solid'
          }
        },
    collapsible: {
          slots: {
            root: '',
            content: 'data-[state=open]:animate-[collapsible-down_200ms_ease-out] data-[state=closed]:animate-[collapsible-up_200ms_ease-out] data-[state=closed]:overflow-hidden'
          }
        },
    button: {
          slots: {
            base: [
              'rounded-md font-medium inline-flex items-center disabled:cursor-not-allowed aria-disabled:cursor-not-allowed disabled:opacity-75 aria-disabled:opacity-75',
              'transition-colors'
            ],
            label: 'truncate',
            leadingIcon: 'shrink-0',
            leadingAvatar: 'shrink-0',
            leadingAvatarSize: '',
            trailingIcon: 'shrink-0'
          },
          variants: {
            fieldGroup: {
              horizontal: 'not-only:first:rounded-e-none not-only:last:rounded-s-none not-last:not-first:rounded-none focus-visible:z-[1]',
              vertical: 'not-only:first:rounded-b-none not-only:last:rounded-t-none not-last:not-first:rounded-none focus-visible:z-[1]'
            },
            color: {
              primary: '',
              secondary: '',
              success: '',
              info: '',
              warning: '',
              error: '',
              neutral: ''
            },
            variant: {
              solid: '',
              outline: '',
              soft: '',
              subtle: '',
              ghost: '',
              link: ''
            },
            size: {
              xs: {
                base: 'px-2 py-1 text-xs gap-1',
                leadingIcon: 'size-4',
                leadingAvatarSize: '3xs',
                trailingIcon: 'size-4'
              },
              sm: {
                base: 'px-2.5 py-1.5 text-xs gap-1.5',
                leadingIcon: 'size-4',
                leadingAvatarSize: '3xs',
                trailingIcon: 'size-4'
              },
              md: {
                base: 'px-2.5 py-1.5 text-sm gap-1.5',
                leadingIcon: 'size-5',
                leadingAvatarSize: '2xs',
                trailingIcon: 'size-5'
              },
              lg: {
                base: 'px-3 py-2 text-sm gap-2',
                leadingIcon: 'size-5',
                leadingAvatarSize: '2xs',
                trailingIcon: 'size-5'
              },
              xl: {
                base: 'px-3 py-2 text-base gap-2',
                leadingIcon: 'size-6',
                leadingAvatarSize: 'xs',
                trailingIcon: 'size-6'
              }
            },
            block: {
              true: {
                base: 'w-full justify-center',
                trailingIcon: 'ms-auto'
              }
            },
            square: {
              true: ''
            },
            leading: {
              true: ''
            },
            trailing: {
              true: ''
            },
            loading: {
              true: ''
            },
            active: {
              true: {
                base: ''
              },
              false: {
                base: ''
              }
            }
          },
          compoundVariants: [
            {
              color: 'primary',
              variant: 'solid',
              class: 'text-inverted bg-primary hover:bg-primary/75 active:bg-primary/75 disabled:bg-primary aria-disabled:bg-primary outline-primary/25 focus-visible:outline-3'
            },
            {
              color: 'primary',
              variant: 'outline',
              class: 'ring ring-inset ring-primary/50 text-primary hover:bg-primary/10 active:bg-primary/10 disabled:bg-transparent aria-disabled:bg-transparent dark:disabled:bg-transparent dark:aria-disabled:bg-transparent outline-primary/25 focus-visible:outline-3 focus-visible:ring-primary'
            },
            {
              color: 'primary',
              variant: 'soft',
              class: 'text-primary bg-primary/10 hover:bg-primary/15 active:bg-primary/15 outline-primary/25 focus-visible:outline-3 disabled:bg-primary/10 aria-disabled:bg-primary/10'
            },
            {
              color: 'primary',
              variant: 'subtle',
              class: 'text-primary ring ring-inset ring-primary/25 bg-primary/10 hover:bg-primary/15 active:bg-primary/15 disabled:bg-primary/10 aria-disabled:bg-primary/10 outline-primary/25 focus-visible:outline-3 focus-visible:ring-primary'
            },
            {
              color: 'primary',
              variant: 'ghost',
              class: 'text-primary hover:bg-primary/10 active:bg-primary/10 outline-primary/25 focus-visible:outline-3 disabled:bg-transparent aria-disabled:bg-transparent dark:disabled:bg-transparent dark:aria-disabled:bg-transparent'
            },
            {
              color: 'primary',
              variant: 'link',
              class: 'text-primary hover:text-primary/75 active:text-primary/75 disabled:text-primary aria-disabled:text-primary outline-primary/25 focus-visible:outline-3'
            },
            {
              color: 'neutral',
              variant: 'solid',
              class: 'text-inverted bg-inverted hover:bg-inverted/90 active:bg-inverted/90 disabled:bg-inverted aria-disabled:bg-inverted outline-inverted/25 focus-visible:outline-3'
            },
            {
              color: 'neutral',
              variant: 'outline',
              class: 'ring ring-inset ring-accented text-default bg-default hover:bg-elevated active:bg-elevated disabled:bg-default aria-disabled:bg-default outline-inverted/25 focus-visible:outline-3 focus-visible:ring-inverted'
            },
            {
              color: 'neutral',
              variant: 'soft',
              class: 'text-default bg-elevated hover:bg-accented/75 active:bg-accented/75 outline-inverted/25 focus-visible:outline-3 disabled:bg-elevated aria-disabled:bg-elevated'
            },
            {
              color: 'neutral',
              variant: 'subtle',
              class: 'ring ring-inset ring-accented text-default bg-elevated hover:bg-accented/75 active:bg-accented/75 disabled:bg-elevated aria-disabled:bg-elevated outline-inverted/25 focus-visible:outline-3 focus-visible:ring-inverted'
            },
            {
              color: 'neutral',
              variant: 'ghost',
              class: 'text-default hover:bg-elevated active:bg-elevated outline-inverted/25 focus-visible:outline-3 hover:disabled:bg-transparent dark:hover:disabled:bg-transparent hover:aria-disabled:bg-transparent dark:hover:aria-disabled:bg-transparent'
            },
            {
              color: 'neutral',
              variant: 'link',
              class: 'text-muted hover:text-default active:text-default disabled:text-muted aria-disabled:text-muted outline-inverted/25 focus-visible:outline-3'
            },
            {
              size: 'xs',
              square: true,
              class: 'p-1'
            },
            {
              size: 'sm',
              square: true,
              class: 'p-1.5'
            },
            {
              size: 'md',
              square: true,
              class: 'p-1.5'
            },
            {
              size: 'lg',
              square: true,
              class: 'p-2'
            },
            {
              size: 'xl',
              square: true,
              class: 'p-2'
            },
            {
              loading: true,
              leading: true,
              class: {
                leadingIcon: 'animate-spin'
              }
            },
            {
              loading: true,
              leading: false,
              trailing: true,
              class: {
                trailingIcon: 'animate-spin'
              }
            }
          ],
          defaultVariants: {
            color: 'primary',
            variant: 'solid',
            size: 'md'
          }
        },
    main: {
          base: 'min-h-[calc(100vh-var(--ui-header-height))]'
        },
    header: {
          slots: {
            root: 'bg-default/75 backdrop-blur border-b border-default h-(--ui-header-height) sticky top-0 z-50',
            container: 'flex items-center justify-between gap-3 h-full',
            left: 'lg:flex-1 flex items-center gap-1.5',
            center: 'hidden lg:flex',
            right: 'flex items-center justify-end lg:flex-1 gap-1.5',
            title: 'shrink-0 font-bold text-xl text-highlighted flex items-end gap-1.5',
            toggle: 'lg:hidden',
            content: 'lg:hidden',
            overlay: 'lg:hidden',
            header: 'px-4 sm:px-6 h-(--ui-header-height) shrink-0 flex items-center justify-between gap-3',
            body: 'p-4 sm:p-6 overflow-y-auto'
          },
          variants: {
            toggleSide: {
              left: {
                toggle: '-ms-1.5'
              },
              right: {
                toggle: '-me-1.5'
              }
            }
          }
        },
    footer: {
          slots: {
            root: '',
            top: 'py-8 lg:py-12',
            bottom: 'py-8 lg:py-12',
            container: 'py-8 lg:py-4 lg:flex lg:items-center lg:justify-between lg:gap-x-3',
            left: 'flex items-center justify-center lg:justify-start lg:flex-1 gap-x-1.5 mt-3 lg:mt-0 lg:order-1',
            center: 'mt-3 lg:mt-0 lg:order-2 flex items-center justify-center',
            right: 'lg:flex-1 flex items-center justify-center lg:justify-end gap-x-1.5 lg:order-3'
          }
        },
    container: {
          base: 'w-full max-w-(--ui-container) mx-auto px-4 sm:px-6 lg:px-8'
        },
    error: {
          slots: {
            root: 'min-h-[calc(100vh-var(--ui-header-height))] flex flex-col items-center justify-center text-center',
            leading: 'mb-4 flex items-center justify-center',
            leadingIcon: 'size-10 shrink-0 text-primary',
            statusCode: 'text-base font-semibold text-primary',
            statusMessage: 'mt-2 text-4xl sm:text-5xl font-bold text-highlighted text-balance',
            message: 'mt-4 text-lg text-muted text-balance',
            links: 'mt-8 flex items-center justify-center gap-6'
          }
        }
  }
})
