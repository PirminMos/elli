// Erzeugt src/changelog.json aus der Git-Historie.
// Wird vom .githooks/post-commit-Hook nach jedem Commit aufgerufen, damit der
// ausgelieferte Stand stets den aktuellen Changelog (wie auf GitHub) enthält.
// Bei Fehlern (kein Git, kein Repo) bleibt eine vorhandene Datei unverändert.
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const out = join(root, 'src', 'changelog.json');
const SEP = '@@ELLI@@'; // sehr unwahrscheinlicher Feldtrenner je Commit-Zeile

try {
  const raw = execFileSync(
    'git',
    ['log', '-100', '--no-merges', `--pretty=format:%h${SEP}%ad${SEP}%s`, '--date=short'],
    { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }
  );

  const commits = raw
    .split('\n')
    .filter(Boolean)
    .map((line) => {
      const parts = line.split(SEP);
      return { hash: parts[0], date: parts[1], subject: parts.slice(2).join(SEP) };
    });

  const data = { generated: new Date().toISOString().slice(0, 10), commits };
  writeFileSync(out, JSON.stringify(data, null, 2) + '\n', 'utf8');
  console.log(`changelog: ${commits.length} Commits -> src/changelog.json`);
} catch (e) {
  // Kein Git verfügbar o. Ä. – Datei unangetastet lassen, Build nicht brechen.
  console.warn('changelog: uebersprungen (' + (e && e.message ? e.message : e) + ')');
}
