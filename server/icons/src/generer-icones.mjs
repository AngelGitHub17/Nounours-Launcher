import puppeteer from 'puppeteer-core';
import fs from 'fs';
const BLUE = 'url(#gb)';
const glyphs = {
  // NounoursLauncher : flèche de téléchargement
  'nounours-launcher': ['#f6b76c', '#2c7be5', `
    <g transform="translate(145 181) scale(0.5)" fill="url(#gdl)" stroke="url(#gdl)" stroke-linejoin="round" stroke-width="22">
      <rect x="190" y="8" width="66" height="200" rx="10"/>
      <path d="M122 214 H322 L222 326 Z"/>
      <path d="M24 312 H60 V384 H386 V312 H422 V428 H24 Z"/>
    </g>`],
  // monogrammes
  didikaria: ['#f6b76c', '#2c7be5', `<text x="256" y="335" text-anchor="middle" font-family="Inter" font-weight="900" font-size="128" fill="${BLUE}" letter-spacing="-4">DK</text>`],
  nounourscorp: ['#ffd36b', '#e4791c', `<text x="256" y="335" text-anchor="middle" font-family="Inter" font-weight="900" font-size="128" fill="url(#go)" letter-spacing="-4">NC</text>`],
  // bloc d'herbe isométrique
  nsdkplus: ['#9fdcff', '#2f9a4a', `
    <g transform="translate(256 300)">
      <path d="M0 -105 L98 -52 L0 1 L-98 -52 Z" fill="#6cc644"/>
      <path d="M0 -105 L98 -52 L0 1 L-98 -52 Z" fill="url(#pix)" opacity=".35"/>
      <path d="M-98 -52 L0 1 L0 112 L-98 59 Z" fill="#9b6a3f"/>
      <path d="M98 -52 L0 1 L0 112 L98 59 Z" fill="#7a5130"/>
      <path d="M-98 -52 L0 1 L0 28 L-98 -25 Z" fill="#58b136"/>
      <path d="M98 -52 L0 1 L0 28 L98 -25 Z" fill="#47962b"/>
      <path d="M-70 -10 h14 v14 h-14z M-40 30 h14 v14 h-14z M-80 40 h14 v14 h-14z" fill="#86593a"/>
      <path d="M30 40 h14 v14 h-14z M62 0 h14 v14 h-14z M50 60 h14 v14 h-14z" fill="#5f3e24"/>
    </g>`],
  // télé
  nounourstv: ['#ff7a7a', '#7b2fe0', `
    <g transform="translate(256 300)">
      <path d="M-40 -88 L-6 -58 M44 -92 L8 -58" stroke="#3a3f55" stroke-width="10" stroke-linecap="round"/>
      <rect x="-112" y="-62" width="224" height="150" rx="26" fill="#2b2f45"/>
      <rect x="-96" y="-47" width="192" height="120" rx="16" fill="url(#gtv)"/>
      <path d="M-22 -18 L36 13 L-22 44 Z" fill="#fff"/>
    </g>`],
  // écran d'information
  nounoursscreeninfo: ['#62e6d8', '#2563eb', `
    <g transform="translate(256 290)">
      <rect x="-110" y="-78" width="220" height="144" rx="18" fill="#26314d"/>
      <rect x="-96" y="-64" width="192" height="116" rx="10" fill="#e9f5ff"/>
      <circle cx="-52" cy="-14" r="27" fill="#2b7be0"/>
      <rect x="-56" y="-12" width="8" height="24" rx="4" fill="#fff"/><circle cx="-52" cy="-26" r="5" fill="#fff"/>
      <rect x="-10" y="-38" width="86" height="12" rx="6" fill="#2b7be0"/>
      <rect x="-10" y="-16" width="70" height="10" rx="5" fill="#9fb6d6"/>
      <rect x="-10" y="2" width="80" height="10" rx="5" fill="#9fb6d6"/>
      <rect x="-80" y="30" width="150" height="10" rx="5" fill="#c6d6ea"/>
      <rect x="-14" y="66" width="28" height="22" fill="#26314d"/>
      <rect x="-56" y="86" width="112" height="14" rx="7" fill="#26314d"/>
    </g>`],
  // bulles de discussion
  communaute: ['#7ccfff', '#1a6fe6', `
    <g transform="translate(256 292)">
      <path d="M-100 -70 h120 a30 30 0 0 1 30 30 v54 a30 30 0 0 1 -30 30 h-62 l-36 30 v-30 h-22 a30 30 0 0 1 -30 -30 v-54 a30 30 0 0 1 30 -30z" fill="#1e8cff"/>
      <circle cx="-74" cy="-13" r="11" fill="#fff"/><circle cx="-40" cy="-13" r="11" fill="#fff"/><circle cx="-6" cy="-13" r="11" fill="#fff"/>
      <path d="M-6 0 h96 a28 28 0 0 1 28 28 v46 a28 28 0 0 1 -28 28 h-14 v28 l-34 -28 h-48 a28 28 0 0 1 -28 -28 v-46 a28 28 0 0 1 28 -28z" fill="#ff9b3d" stroke="#fff" stroke-width="8"/>
      <path d="M22 44 q20 22 46 0" stroke="#fff" stroke-width="10" fill="none" stroke-linecap="round"/>
    </g>`],
  // boîte de Pandore entrouverte
  pandorpacks: ['#cf9bff', '#4a1aa6', `
    <g transform="translate(256 318)">
      <path d="M-64 -86 L-40 -150 M0 -96 L0 -168 M64 -86 L40 -150" stroke="#ffd84d" stroke-width="12" stroke-linecap="round" opacity=".9"/>
      <path d="M-30 -110 l8 -18 8 18 18 8 -18 8 -8 18 -8 -18 -18 -8z" fill="#fff6b0" transform="translate(70 -10)"/>
      <path d="M0 -66 L94 -24 L0 18 L-94 -24 Z" fill="#ffe27a"/>
      <path d="M-94 -24 L0 18 L0 104 L-94 62 Z" fill="#7c3aed"/>
      <path d="M94 -24 L0 18 L0 104 L94 62 Z" fill="#5b21b6"/>
      <path d="M-94 -24 L0 18 L0 34 L-94 -8 Z M94 -24 L0 18 L0 34 L94 -8 Z" fill="#f4b740"/>
      <path d="M-102 -52 L0 -98 L102 -52 L102 -40 L0 6 L-102 -40 Z" fill="#9d5cf5"/>
      <path d="M-102 -52 L0 -98 L102 -52 L0 -6 Z" fill="#b98bff"/>
      <rect x="-12" y="40" width="24" height="30" rx="5" fill="#f4b740"/>
    </g>`],
  // cloche de service
  nounoursservicenow: ['#ffcf8a', '#d9480f', `
    <g transform="translate(256 300)">
      <circle cx="0" cy="-86" r="13" fill="#c99a2e"/>
      <path d="M-96 34 C-96 -40 -50 -74 0 -74 C50 -74 96 -40 96 34 Z" fill="#f5c344"/>
      <path d="M-60 -10 C-52 -40 -30 -54 -8 -58" stroke="#fff3c4" stroke-width="12" fill="none" stroke-linecap="round"/>
      <rect x="-120" y="34" width="240" height="26" rx="13" fill="#3f4f7f"/>
    </g>`],
  // caisse enregistreuse
  nounourscaisse: ['#8ce99a', '#2b8a3e', `
    <g transform="translate(256 300)">
      <rect x="-40" y="-104" width="80" height="34" rx="8" fill="#26314d"/><rect x="-28" y="-96" width="56" height="16" rx="4" fill="#8ce99a"/>
      <path d="M-96 -64 h192 l14 96 h-220 z" fill="#3f4f7f"/>
      ${[-64,-22,20,62].map(x => [-44,-14].map(y => `<rect x="${x-14}" y="${y}" width="28" height="18" rx="5" fill="#e9f0ff"/>`).join('')).join('')}
      <rect x="-118" y="32" width="236" height="62" rx="12" fill="#26314d"/>
      <rect x="-30" y="52" width="60" height="10" rx="5" fill="#8ce99a"/>
    </g>`],
  // avant de train
  railboard: ['#74c0fc', '#1c4f9c', `
    <g transform="translate(256 290)">
      <rect x="-82" y="-104" width="164" height="190" rx="46" fill="#1f6feb"/>
      <rect x="-62" y="-80" width="124" height="70" rx="16" fill="#dff0ff"/>
      <rect x="-82" y="10" width="164" height="16" fill="#ffd43b"/>
      <circle cx="-44" cy="52" r="14" fill="#fff3b0"/><circle cx="44" cy="52" r="14" fill="#fff3b0"/>
      <path d="M-54 86 L-80 124 M54 86 L80 124" stroke="#26314d" stroke-width="12" stroke-linecap="round"/>
      <rect x="-110" y="118" width="220" height="12" rx="6" fill="#26314d"/>
    </g>`],
  // ---- icônes par type (projets sans icône) ----
  'type-site': ['#8fb8ff', '#3b5bdb', `
    <g transform="translate(256 292)">
      <rect x="-110" y="-80" width="220" height="160" rx="20" fill="#26314d"/>
      <rect x="-98" y="-50" width="196" height="118" rx="10" fill="#eef4ff"/>
      <circle cx="-86" cy="-65" r="6" fill="#ff6b6b"/><circle cx="-68" cy="-65" r="6" fill="#ffd166"/><circle cx="-50" cy="-65" r="6" fill="#4cd97b"/>
      <rect x="-84" y="-36" width="168" height="34" rx="8" fill="#3b7be0"/>
      <rect x="-84" y="8" width="76" height="46" rx="8" fill="#a9c4ee"/>
      <rect x="4" y="8" width="80" height="12" rx="6" fill="#a9c4ee"/><rect x="4" y="28" width="60" height="12" rx="6" fill="#c9d8f0"/>
    </g>`],
  'type-app': ['#ffb27a', '#e0457b', `
    <g transform="translate(256 292) rotate(45)">
      <path d="M0 -112 C46 -80 52 -20 40 40 L-40 40 C-52 -20 -46 -80 0 -112Z" fill="#eef2fb"/>
      <circle cx="0" cy="-36" r="22" fill="#3b7be0" stroke="#26314d" stroke-width="8"/>
      <path d="M-40 10 L-74 52 L-40 46Z M40 10 L74 52 L40 46Z" fill="#e0457b"/>
      <path d="M-24 46 L0 98 L24 46Z" fill="#ffb02e"/>
    </g>`],
  'type-plugin': ['#b8e986', '#3f8f3a', `
    <g transform="translate(256 292) rotate(-45)">
      <rect x="-11" y="-60" width="22" height="168" rx="8" fill="#9b6a3f"/>
      <path d="M-104 -46 C-60 -96 60 -96 104 -46 L84 -34 C40 -70 -40 -70 -84 -34 Z" fill="#8fd8ff" stroke="#26314d" stroke-width="8" stroke-linejoin="round"/>
    </g>`],
  'type-service': ['#9aa7c7', '#3f4f7f', `
    <g transform="translate(256 292)">
      <g fill="#3f4f7f">${Array.from({length: 8}, (_, i) => `<rect x="-17" y="-104" width="34" height="40" rx="8" transform="rotate(${i * 45})"/>`).join('')}</g>
      <circle r="80" fill="#3f4f7f"/><circle r="34" fill="#fff"/>
      <circle r="34" fill="none" stroke="#9ec1ff" stroke-width="10"/>
    </g>`],
  'type-bot': ['#7fe0c4', '#16867a', `
    <g transform="translate(256 300)">
      <line x1="0" y1="-112" x2="0" y2="-80" stroke="#26314d" stroke-width="10"/><circle cy="-116" r="13" fill="#ff6b6b"/>
      <rect x="-96" y="-80" width="192" height="150" rx="40" fill="#26314d"/>
      <rect x="-76" y="-58" width="152" height="90" rx="26" fill="#bff5e6"/>
      <circle cx="-34" cy="-14" r="16" fill="#16867a"/><circle cx="34" cy="-14" r="16" fill="#16867a"/>
      <rect x="-30" y="44" width="60" height="10" rx="5" fill="#bff5e6"/>
    </g>`],
  'type-tool': ['#ffd86b', '#e07b1f', `
    <g transform="translate(256 292) rotate(45)">
      <rect x="-17" y="-40" width="34" height="150" rx="15" fill="#3f4f7f"/>
      <circle cx="0" cy="-70" r="56" fill="#3f4f7f"/>
      <rect x="-20" y="-140" width="40" height="72" rx="6" fill="#fff"/>
    </g>`],
  'type-pack': ['#cf9bff', '#6d28d9', `
    <g transform="translate(256 300)">
      <path d="M0 -100 L96 -50 L0 0 L-96 -50 Z" fill="#c4a3ff"/>
      <path d="M-96 -50 L0 0 L0 108 L-96 58 Z" fill="#7c3aed"/>
      <path d="M96 -50 L0 0 L0 108 L96 58 Z" fill="#5b21b6"/>
    </g>`],
};
const svg = (top, bottom, g) => `<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">
<defs>
  <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="${top}"/><stop offset="1" stop-color="${bottom}"/></linearGradient>
  <linearGradient id="gloss" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".35"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient>
  <linearGradient id="gb" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#3d82ee"/><stop offset="1" stop-color="#1b4fb5"/></linearGradient>
  <linearGradient id="gdl" x1="0" y1="0" x2="0" y2="440" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#3d82ee"/><stop offset="1" stop-color="#1b4fb5"/></linearGradient>
  <linearGradient id="go" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#f29a2e"/><stop offset="1" stop-color="#c25b0c"/></linearGradient>
  <linearGradient id="gtv" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff5f6d"/><stop offset="1" stop-color="#8b3dff"/></linearGradient>
  <pattern id="pix" width="28" height="28" patternUnits="userSpaceOnUse"><rect width="14" height="14" fill="#2e7d1f"/><rect x="14" y="14" width="14" height="14" fill="#2e7d1f"/></pattern>
  <filter id="sh" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="8" stdDeviation="10" flood-color="#000" flood-opacity=".28"/></filter>
  <clipPath id="sq"><rect x="20" y="20" width="472" height="472" rx="110"/></clipPath>
  <clipPath id="head"><circle cx="256" cy="290" r="146"/></clipPath>
</defs>
<rect x="20" y="20" width="472" height="472" rx="110" fill="url(#bg)"/>
<g clip-path="url(#sq)"><ellipse cx="256" cy="40" rx="360" ry="190" fill="url(#gloss)"/></g>
<g filter="url(#sh)">
  <circle cx="160" cy="130" r="58" fill="#fff"/><circle cx="352" cy="130" r="58" fill="#fff"/>
  <circle cx="256" cy="290" r="150" fill="#fff"/>
</g>
<circle cx="160" cy="130" r="30" fill="#fcd5a6"/><circle cx="352" cy="130" r="30" fill="#fcd5a6"/>
<g clip-path="url(#head)">${g}</g>
</svg>`;
const b = await puppeteer.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const p = await b.newPage();
await p.setViewport({ width: 512, height: 512 });
for (const [name, [t, bo, g]] of Object.entries(glyphs)) {
  const s = svg(t, bo, g);
  fs.writeFileSync(`/tmp/icons/out/${name}.svg`, s);
  await p.setContent(`<html><body style="margin:0;background:transparent">${s}</body></html>`);
  await p.evaluate(() => document.fonts.ready);
  await p.screenshot({ path: `/tmp/icons/out/${name}.png`, omitBackground: true, clip: { x: 0, y: 0, width: 512, height: 512 } });
}
// Version 1024 px pour les icônes de l'application
{ const big = await b.newPage(); await big.setViewport({ width: 512, height: 512, deviceScaleFactor: 2 });
  await big.setContent(`<html><body style="margin:0;background:transparent">${fs.readFileSync('/tmp/icons/out/nounours-launcher.svg')}</body></html>`);
  await big.screenshot({ path: '/tmp/icons/out/nounours-launcher-1024.png', omitBackground: true, clip: { x: 0, y: 0, width: 512, height: 512 } }); }
await b.close();
