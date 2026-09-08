// Accept the published legacy and current policies without requiring obsolete wording.
export function verifyOuterDeliveryPolicy(text) {
  const clauses = [
    {
      label: 'delivery completeness policy',
      versions: [
        'Feature delivery gets roughly 80–90%',
        'Prioritize accurate, complete user-visible outcomes.',
      ],
    },
    {
      label: 'evidence-driven inspection policy',
      versions: [
        'After three targeted inspections',
        'When inspection stops producing evidence, change the hypothesis or observation method.',
      ],
    },
  ];
  return clauses
    .filter(({ versions }) => !versions.some((clause) => text.includes(clause)))
    .map(({ label }) => `outer AGENTS.md is missing required ${label}`);
}
