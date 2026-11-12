// Mini-rendu Markdown sûr (descriptions et notes de version).
// Tout est échappé d'abord ; sont reconnus : titres, listes (imbriquées par l'indentation),
// gras, italique, code, liens [texte](https://…) et adresses https:// nues.

export const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

function inline(s) {
  // Les liens Markdown d'abord, puis les adresses nues hors liens déjà faits
  const links = [];
  s = s.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, (_, t, u) => { links.push(`<a href="${u}" data-ext>${t}</a>`); return `\u0000${links.length - 1}\u0000`; });
  s = s.replace(/(^|[\s(])(https?:\/\/[^\s<)]+[^\s<).,;:!?])/g, (_, pre, u) => {
    const label = u.replace(/^https?:\/\//, '').replace(/\/$/, '');
    links.push(`<a href="${u}" data-ext>${label}</a>`);
    return `${pre}\u0000${links.length - 1}\u0000`;
  });
  s = s
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
    .replace(/(^|[^*\w])\*([^*\s][^*]*)\*/g, '$1<i>$2</i>')
    .replace(/(^|[^\w])_([^_\s][^_]*)_(?=[^\w]|$)/g, '$1<i>$2</i>');
  return s.replace(/\u0000(\d+)\u0000/g, (_, i) => links[+i]);
}

export function md(src) {
  const lines = esc(src).replace(/\r/g, '').split('\n');
  let html = '';
  const stack = []; // listes ouvertes : { type: 'ul'|'ol', indent }
  const closeTo = indent => {
    while (stack.length && stack[stack.length - 1].indent > indent) html += `</li></${stack.pop().type}>`;
  };
  const closeAll = () => { while (stack.length) html += `</li></${stack.pop().type}>`; };
  let para = [];
  const flushPara = () => { if (para.length) { html += `<p>${inline(para.join(' '))}</p>`; para = []; } };

  for (const raw of lines) {
    const line = raw.replace(/\t/g, '  ').trimEnd();
    let m;
    if ((m = line.match(/^(\s*)([-*+]|\d+[.)])\s+(.*)$/))) {
      flushPara();
      const indent = m[1].length, type = /\d/.test(m[2]) ? 'ol' : 'ul';
      closeTo(indent);
      const top = stack[stack.length - 1];
      if (!top || indent > top.indent) { html += `<${type}><li>`; stack.push({ type, indent }); }
      else if (top.type !== type) { html += `</li></${stack.pop().type}><${type}><li>`; stack.push({ type, indent }); }
      else html += '</li><li>';
      html += inline(m[3]);
    } else if ((m = line.match(/^(#{1,4})\s+(.*)$/))) {
      flushPara(); closeAll();
      const n = Math.min(6, m[1].length + 2);
      html += `<h${n}>${inline(m[2])}</h${n}>`;
    } else if (line.trim() === '') {
      flushPara(); closeAll();
    } else if (stack.length && /^\s+/.test(line)) {
      html += ' ' + inline(line.trim()); // suite d'un élément de liste sur la ligne suivante
    } else {
      closeAll();
      para.push(line.trim());
    }
  }
  flushPara(); closeAll();
  return html;
}
