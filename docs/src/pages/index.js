/**
 * Integriq landing page.
 *
 * Composes the brand <DetailHero> + <WidgetShelf> from
 * @conduction/docusaurus-preset/components, mirroring the connext page
 * at sites/www/src/pages/apps/openconnector.mdx.
 *
 * Written as .js (not .mdx) because authoring the page in JSX keeps
 * the same component composition while sidestepping an MDX-ESM parser
 * collision seen on sibling docs sites in this Docusaurus 3.7 + this
 * preset combination.
 */

import React from 'react';
import Layout from '@theme/Layout';
import {
  DetailHero,
  WidgetShelf,
  AppMock,
} from '@conduction/docusaurus-preset/components';

const INTEGRIQ_ICON = (
  <svg viewBox="0 0 24 24">
    <circle cx="6" cy="12" r="3" />
    <circle cx="18" cy="6" r="3" />
    <circle cx="18" cy="18" r="3" />
    <path d="M9 12h9M9 12l9-6M9 12l9 6" />
  </svg>
);

const TAGLINE = (
  <>
    The integration layer for <span className="next-blue">Nextcloud</span>.
    REST, SOAP, GraphQL, file drops, message queues. Pulls data from your
    existing systems into typed registers without writing glue code.
  </>
);

function RecentRunsPanel() {
  const rows = [
    { tone: 'var(--c-mint-500)', dur: '12s' },
    { tone: 'var(--c-mint-500)', dur: '8s' },
    { tone: 'var(--c-orange-knvb)', dur: '24s' },
    { tone: 'var(--c-mint-500)', dur: '14s' },
    { tone: 'var(--c-red-vermillion)', dur: '38s' },
    { tone: 'var(--c-mint-500)', dur: '6s' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {rows.map((row, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
            padding: '3px 0',
            borderBottom:
              i < rows.length - 1 ? '1px solid var(--c-cobalt-50)' : 'none',
          }}
        >
          <span
            style={{
              width: 8,
              height: 9,
              clipPath: 'var(--hex-pointy-top)',
              background: row.tone,
              flexShrink: 0,
            }}
          />
          <div
            style={{
              flex: 1,
              height: 3,
              background: 'var(--c-cobalt-200)',
              borderRadius: 1,
            }}
          />
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 9,
              color: 'var(--c-cobalt-400)',
              minWidth: 32,
              textAlign: 'right',
            }}
          >
            {row.dur}
          </div>
        </div>
      ))}
    </div>
  );
}

function MappingPanel() {
  const fields = [
    { l: '70%', r: '60%' },
    { l: '50%', r: '70%' },
    { l: '65%', r: '45%' },
    { l: '55%', r: '60%' },
  ];
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {fields.map((field, i) => (
        <div
          key={i}
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 8,
          }}
        >
          <div
            style={{
              flex: 1,
              height: 5,
              width: field.l,
              background: 'var(--c-cobalt-300)',
              borderRadius: 1,
            }}
          />
          <svg
            viewBox="0 0 16 8"
            style={{ width: 16, height: 8, flexShrink: 0 }}
          >
            <path
              d="M 0 4 L 14 4 M 10 0 L 14 4 L 10 8"
              stroke="var(--c-blue-cobalt)"
              strokeWidth="1.5"
              fill="none"
            />
          </svg>
          <div
            style={{
              flex: 1,
              height: 5,
              width: field.r,
              background: 'var(--c-mint-500)',
              borderRadius: 1,
            }}
          />
        </div>
      ))}
    </div>
  );
}

function ScheduleHealthPanel() {
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
      <div
        style={{
          width: 84,
          height: 84,
          borderRadius: '50%',
          background:
            'conic-gradient(var(--c-mint-500) 0 78%, var(--c-orange-knvb) 78% 92%, var(--c-red-vermillion) 92% 100%)',
          position: 'relative',
          flexShrink: 0,
        }}
      >
        <div
          style={{
            position: 'absolute',
            inset: 14,
            background: 'var(--c-cobalt-50)',
            borderRadius: '50%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <div
            style={{
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 16,
              fontWeight: 700,
              color: 'var(--c-cobalt-900)',
            }}
          >
            78%
          </div>
        </div>
      </div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        {[
          { tone: 'var(--c-mint-500)', label: 'OK' },
          { tone: 'var(--c-orange-knvb)', label: 'Retry' },
          { tone: 'var(--c-red-vermillion)', label: 'Failed' },
        ].map((row, i) => (
          <div
            key={i}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 6,
              fontFamily: 'var(--conduction-typography-font-family-code)',
              fontSize: 10,
              color: 'var(--c-cobalt-700)',
            }}
          >
            <span
              style={{
                width: 8,
                height: 8,
                borderRadius: 1,
                background: row.tone,
              }}
            />
            {row.label}
          </div>
        ))}
      </div>
    </div>
  );
}

const WIDGETS = [
  {
    title: 'REST, SOAP, GraphQL connectors',
    desc: 'Six protocols, one config UI. Same configuration shape across REST, SOAP, GraphQL, file drops, message queues, and database connectors.',
    panel: <RecentRunsPanel />,
  },
  {
    title: 'Schema-aware mappings',
    desc: 'Drop a register schema in, Integriq generates the field mapping UI. Validation runs at the connector before the register is touched.',
    panel: <MappingPanel />,
  },
  {
    title: 'Webhook, schedule, manual triggers',
    desc: 'Real-time webhooks for source systems that can push, cron-style schedules for those that cannot, manual run for migration cut-overs.',
    panel: <ScheduleHealthPanel />,
  },
];

export default function Home() {
  return (
    <Layout
      title="Integriq, the integration layer for Nextcloud"
      description="The integration layer for Nextcloud. REST, SOAP, GraphQL, file drops, message queues. Pulls data into typed registers without writing glue code."
    >
      <main className="marketing-page">
        <DetailHero
          appId="openconnector"
          background="cobalt"
          status={{ label: 'Beta', color: 'var(--c-orange-knvb)' }}
          version="v1.2"
          locales="EN"
          title="Integriq"
          tagline={TAGLINE}
          primaryCta={{
            label: 'Install from app store',
            href: 'https://apps.nextcloud.com/apps/openconnector',
            tone: 'orange',
          }}
          secondaryCta={{ label: 'Read the docs', href: '/docs/intro' }}
          tertiaryCta={{
            label: 'View on GitHub',
            href: 'https://github.com/ConductionNL/integriq',
          }}
          iconColor="var(--c-orange-knvb)"
          icon={INTEGRIQ_ICON}
          illustration={<AppMock app="openconnector" />}
        />

        <WidgetShelf
          eyebrow="What it does"
          title="Source to register, observed end to end."
          lede="Configure a source, map fields to a register schema, set the trigger, and Integriq keeps the register filled. Bidirectional, audited, and visible on every dashboard."
          widgets={WIDGETS}
        />
      </main>
    </Layout>
  );
}
