// Frame Design System — shared icon web component.
// Usage: <ds-icon glyph="upload" size="18" sw="1.7"></ds-icon>
// Inherits color via currentColor. Loaded once (de-duped by URL) in each DC helmet.
(function () {
  if (window.customElements && customElements.get('ds-icon')) return;

  const ICONS = {
    grid: [{ t: 'rect', x: 3, y: 3, width: 7.5, height: 7.5, rx: 1.5 }, { t: 'rect', x: 13.5, y: 3, width: 7.5, height: 7.5, rx: 1.5 }, { t: 'rect', x: 3, y: 13.5, width: 7.5, height: 7.5, rx: 1.5 }, { t: 'rect', x: 13.5, y: 13.5, width: 7.5, height: 7.5, rx: 1.5 }],
    image: [{ t: 'rect', x: 3, y: 3, width: 18, height: 18, rx: 2.5 }, { t: 'circle', cx: 8.5, cy: 8.5, r: 1.6 }, { t: 'path', d: 'M21 15l-5-5L5 21' }],
    layers: [{ t: 'path', d: 'M12 2 2 7l10 5 10-5-10-5z' }, { t: 'path', d: 'M2 17l10 5 10-5' }, { t: 'path', d: 'M2 12l10 5 10-5' }],
    sliders: [{ t: 'path', d: 'M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3' }, { t: 'path', d: 'M1 14h6M9 8h6M17 16h6' }],
    search: [{ t: 'circle', cx: 11, cy: 11, r: 7 }, { t: 'path', d: 'M21 21l-4.3-4.3' }],
    upload: [{ t: 'path', d: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' }, { t: 'path', d: 'M17 8l-5-5-5 5' }, { t: 'path', d: 'M12 3v12' }],
    bell: [{ t: 'path', d: 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9' }, { t: 'path', d: 'M13.73 21a2 2 0 0 1-3.46 0' }],
    chevronDown: [{ t: 'path', d: 'M6 9l6 6 6-6' }],
    chevronRight: [{ t: 'path', d: 'M9 6l6 6-6 6' }],
    chevronLeft: [{ t: 'path', d: 'M15 6l-6 6 6 6' }],
    check: [{ t: 'path', d: 'M20 6L9 17l-5-5' }],
    x: [{ t: 'path', d: 'M18 6L6 18M6 6l12 12' }],
    plus: [{ t: 'path', d: 'M12 5v14M5 12h14' }],
    sun: [{ t: 'circle', cx: 12, cy: 12, r: 4 }, { t: 'path', d: 'M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4' }],
    moon: [{ t: 'path', d: 'M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z' }],
    film: [{ t: 'rect', x: 2, y: 3, width: 20, height: 18, rx: 2.5 }, { t: 'path', d: 'M7 3v18M17 3v18M2 9h5M2 15h5M17 9h5M17 15h5M7 12h10' }],
    play: [{ t: 'path', d: 'M6 4l14 8-14 8z' }],
    folder: [{ t: 'path', d: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' }],
    trash: [{ t: 'path', d: 'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6' }],
    download: [{ t: 'path', d: 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4' }, { t: 'path', d: 'M7 10l5 5 5-5' }, { t: 'path', d: 'M12 15V3' }],
    edit: [{ t: 'path', d: 'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7' }, { t: 'path', d: 'M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z' }],
    more: [{ t: 'circle', cx: 5, cy: 12, r: 1.4 }, { t: 'circle', cx: 12, cy: 12, r: 1.4 }, { t: 'circle', cx: 19, cy: 12, r: 1.4 }],
    user: [{ t: 'circle', cx: 12, cy: 8, r: 4 }, { t: 'path', d: 'M6 21v-1a6 6 0 0 1 12 0v1' }],
    logout: [{ t: 'path', d: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4' }, { t: 'path', d: 'M16 17l5-5-5-5' }, { t: 'path', d: 'M21 12H9' }],
    list: [{ t: 'path', d: 'M8 6h13M8 12h13M8 18h13' }, { t: 'path', d: 'M3 6h.01M3 12h.01M3 18h.01' }],
    clock: [{ t: 'circle', cx: 12, cy: 12, r: 9 }, { t: 'path', d: 'M12 7v5l3 2' }],
    eye: [{ t: 'path', d: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z' }, { t: 'circle', cx: 12, cy: 12, r: 3 }],
    tag: [{ t: 'path', d: 'M2 2h9l11 11-9 9L2 11z' }, { t: 'circle', cx: 6.5, cy: 6.5, r: 1.5 }],
    logout2: [{ t: 'path', d: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4' }],
    alert: [{ t: 'path', d: 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z' }, { t: 'path', d: 'M12 9v4M12 17h.01' }],
    checkCircle: [{ t: 'path', d: 'M22 11.1V12a10 10 0 1 1-5.9-9.1' }, { t: 'path', d: 'M22 4 12 14.1l-3-3' }],
    hardDrive: [{ t: 'path', d: 'M22 12H2' }, { t: 'path', d: 'M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z' }, { t: 'path', d: 'M6 16h.01M10 16h.01' }],
    panelLeft: [{ t: 'rect', x: 3, y: 3, width: 18, height: 18, rx: 2.5 }, { t: 'path', d: 'M9.5 3v18' }],
    arrowLeft: [{ t: 'path', d: 'M19 12H5M12 19l-7-7 7-7' }],
    info: [{ t: 'circle', cx: 12, cy: 12, r: 10 }, { t: 'path', d: 'M12 16v-4M12 8h.01' }],
    filter: [{ t: 'path', d: 'M22 3H2l8 9.5V19l4 2v-8.5z' }],
    bolt: [{ t: 'path', d: 'M13 2 3 14h7l-1 8 10-12h-7z' }],
    copy: [{ t: 'rect', x: 9, y: 9, width: 12, height: 12, rx: 2 }, { t: 'path', d: 'M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1' }],
    star: [{ t: 'path', d: 'M12 3l2.9 6 6.6.9-4.8 4.6 1.1 6.5L12 18l-5.8 3 1.1-6.5L2.5 9.9 9 9z' }],
    settings: [{ t: 'circle', cx: 12, cy: 12, r: 3 }, { t: 'path', d: 'M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z' }],
    compass: [{ t: 'circle', cx: 12, cy: 12, r: 10 }, { t: 'path', d: 'M16.2 7.8l-2.9 6.4-6.4 2.9 2.9-6.4z' }],
    type: [{ t: 'path', d: 'M4 7V4h16v3' }, { t: 'path', d: 'M9 20h6' }, { t: 'path', d: 'M12 4v16' }],
    alignLeft: [{ t: 'path', d: 'M3 6h18M3 12h12M3 18h15' }],
    hash: [{ t: 'path', d: 'M4 9h16M4 15h16M10 3 8 21M16 3l-2 18' }],
    calendar: [{ t: 'rect', x: 3, y: 4, width: 18, height: 18, rx: 2.5 }, { t: 'path', d: 'M8 2v4M16 2v4M3 10h18' }],
    calendarRange: [{ t: 'rect', x: 3, y: 4, width: 18, height: 18, rx: 2.5 }, { t: 'path', d: 'M8 2v4M16 2v4M3 10h18M7 15h4M13 15h4' }],
    checkSquare: [{ t: 'rect', x: 3, y: 3, width: 18, height: 18, rx: 2.5 }, { t: 'path', d: 'M9 12l2 2 4-4' }],
    users: [{ t: 'path', d: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2' }, { t: 'circle', cx: 9, cy: 7, r: 4 }, { t: 'path', d: 'M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75' }],
    toggleLeft: [{ t: 'rect', x: 1, y: 5, width: 22, height: 14, rx: 7 }, { t: 'circle', cx: 8, cy: 12, r: 3 }],
    link: [{ t: 'path', d: 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.5 1.5' }, { t: 'path', d: 'M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.5-1.5' }],
    gripVertical: [{ t: 'circle', cx: 9, cy: 6, r: 1 }, { t: 'circle', cx: 9, cy: 12, r: 1 }, { t: 'circle', cx: 9, cy: 18, r: 1 }, { t: 'circle', cx: 15, cy: 6, r: 1 }, { t: 'circle', cx: 15, cy: 12, r: 1 }, { t: 'circle', cx: 15, cy: 18, r: 1 }],
    externalLink: [{ t: 'path', d: 'M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6' }],
    box: [{ t: 'path', d: 'M21 8V16a2 2 0 0 1-1 1.73l-7 4a2 2 0 0 1-2 0l-7-4A2 2 0 0 1 3 16V8a2 2 0 0 1 1-1.73l7-4a2 2 0 0 1 2 0l7 4A2 2 0 0 1 21 8z' }, { t: 'path', d: 'M3.3 7 12 12l8.7-5M12 22V12' }],
    palette: [{ t: 'circle', cx: 13.5, cy: 6.5, r: 1.2 }, { t: 'circle', cx: 17.5, cy: 10.5, r: 1.2 }, { t: 'circle', cx: 8.5, cy: 7.5, r: 1.2 }, { t: 'circle', cx: 6.5, cy: 12.5, r: 1.2 }, { t: 'path', d: 'M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.5-.7 1.5-1.5 0-.4-.2-.8-.4-1-.3-.3-.4-.6-.4-1 0-.8.7-1.5 1.5-1.5H16c3.3 0 6-2.7 6-6 0-4.9-4.5-9-10-9z' }],
  };

  class DSIcon extends HTMLElement {
    static get observedAttributes() { return ['glyph', 'size', 'sw', 'fill']; }
    constructor() {
      super();
      // Render into shadow DOM so React (which owns the light DOM) never sees
      // these nodes — avoids removeChild reconciliation conflicts.
      this.attachShadow({ mode: 'open' });
    }
    connectedCallback() { this.render(); }
    attributeChangedCallback() { this.render(); }
    render() {
      const g = this.getAttribute('glyph');
      const size = this.getAttribute('size') || '18';
      const sw = this.getAttribute('sw') || '1.7';
      const fill = this.getAttribute('fill') || 'none';
      const specs = ICONS[g] || [];
      const inner = specs.map(function (s) {
        const t = s.t;
        const attrs = Object.keys(s).filter(function (k) { return k !== 't'; })
          .map(function (k) { return k + '="' + s[k] + '"'; }).join(' ');
        return '<' + t + ' ' + attrs + '></' + t + '>';
      }).join('');
      this.shadowRoot.innerHTML =
        '<style>:host{display:inline-flex;line-height:0}</style>' +
        '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="' + fill + '" stroke="currentColor" stroke-width="' + sw + '" stroke-linecap="round" stroke-linejoin="round" style="display:block">' + inner + '</svg>';
    }
  }
  customElements.define('ds-icon', DSIcon);
  window.DS_ICON_NAMES = Object.keys(ICONS);
})();
