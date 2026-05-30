// @ts-check

/**
 * OpenConnector documentation site.
 *
 * Built on @conduction/docusaurus-preset for brand defaults (tokens,
 * theme swizzles for Navbar / Footer, four-locale i18n scaffolding,
 * KvK / BTW copyright). Site-specific overrides — locale, sidebar
 * path, custom prism themes, openconnector-only navbar items — are
 * passed through createConfig() opts.
 */

const { createConfig, baseFooterLinks } = require('@conduction/docusaurus-preset');

/* createConfig replaces themes wholesale when `themes:` is passed, so
   we re-include the brand theme plugin alongside @docusaurus/theme-mermaid.
   Without the brand theme entry the Navbar/Footer swizzles and
   brand.css auto-load would silently drop. */
const BRAND_THEME = require.resolve('@conduction/docusaurus-preset/theme');

const config = createConfig({
  title: 'OpenConnector',
  tagline: 'The integration layer for Nextcloud. REST, SOAP, GraphQL, file drops, message queues. Pulls data from your existing systems into typed registers without writing glue code.',
  url: 'https://openconnector.conduction.nl',
  baseUrl: '/',

  organizationName: 'ConductionNL',
  projectName: 'openconnector',

  /* English-only for now. The brand preset's default i18n block
     (nl/en/de/fr) is replaced wholesale here. Re-enable additional
     locales once translated markdown is in place under docs/i18n/. */
  i18n: {
    defaultLocale: 'en',
    locales: ['en'],
    localeConfigs: {
      en: { label: 'English' },
    },
  },

  /* The openconnector docs source lives at the repo root in `docs/`
     while the Docusaurus config + pages live in the sibling
     `docusaurus/` folder. Override the preset's default `presets:`
     block to point `docs.path` at `../docs` and disable the blog
     plugin. customCss carries openconnector-specific CSS only — brand
     tokens and the theme swizzles are auto-loaded by the brand theme
     entry in `themes:` below. */
  presets: [
    [
      'classic',
      {
        docs: {
          path: '../docs',
          /* Exclude node_modules just in case the docs folder ever has
             one (it does today, from a separate dependency install
             during MD tooling). The src/ exclude that launchpad and
             openregister use does not apply here because docs/ has no
             src/ folder; pages live in `../docusaurus/src/pages/`.

             rules.md and administrators-legacy/mapping/mapping.md
             contain raw `{` / `}` expressions inside backtick code
             samples that the Docusaurus 3.10 MDX parser tries to
             evaluate as JSX expressions. They pre-date this preset
             migration; flag for an MDX-cleanup follow-up PR (escape
             `{` as `\{`) and exclude them here so the build can ship. */
          exclude: [
            '**/node_modules/**',
            'rules.md',
            'administrators-legacy/mapping/mapping.md',
          ],
          sidebarPath: require.resolve('./sidebars.js'),
          editUrl: 'https://codeberg.org/Conduction/openconnector/src/branch/main/docs/',
        },
        blog: false,
        theme: {
          customCss: require.resolve('./src/css/custom.css'),
        },
      },
    ],
  ],

  themes: [BRAND_THEME, '@docusaurus/theme-mermaid'],

  /* Brand navbar provides locale dropdown + GitHub by default; we
     replace items[] with openconnector's own (Documentation sidebar
     link, openconnector GitHub link). Object.assign in createConfig is
     shallow, so items: replaces wholesale. */
  navbar: {
    items: [
      {
        type: 'docSidebar',
        sidebarId: 'tutorialSidebar',
        position: 'left',
        label: 'Documentation',
      },
      {
        href: 'https://codeberg.org/Conduction/openconnector',
        label: 'Codeberg',
        position: 'right',
      },
    ],
  },

  /* Per-property footer override: pass `links` only, brand
     `style: 'dark'` and KvK/BTW/IBAN/address copyright string both
     inherit unchanged. Single column: brand "Conduction" anchor. */
  footer: {
    links: [
      ...baseFooterLinks().filter((column) => column.title === 'Conduction'),
    ],
  },

  /* Drop the canal-footer's boat-sinking + kade-cyclist mini-games
     on this product-doc footer. The static skyline + canal decoration
     are kept; the interactive layer goes away. */
  minigames: false,

  /* themeConfig is shallow-merged into the preset's defaults
     (colorMode + navbar + footer). Custom prism + mermaid theme
     overrides land alongside. */
  themeConfig: {
    image: 'img/og-openconnector.png',
    prism: {
      theme: {
        plain: {
          color: '#393A34',
          backgroundColor: '#f6f8fa',
        },
        styles: [],
      },
      darkTheme: {
        plain: {
          color: '#F8F8F2',
          backgroundColor: '#282A36',
        },
        styles: [],
      },
    },
  },
});

/* createConfig doesn't pass-through arbitrary top-level fields; assign
   markdown + onBroken* directly so they make it into the final
   Docusaurus config. */
config.onBrokenLinks = 'warn';
config.onBrokenMarkdownLinks = 'warn';
config.onBrokenAnchors = 'warn';
config.markdown = {
  mermaid: true,
};

module.exports = config;
