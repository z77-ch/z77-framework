/*
 * <lottie-figure> — framework-independent Lottie renderer (custom element).
 *
 * One self-contained web component (framework topic docs/topics/lottie.md; first built in
 * zihlundsee.ch). It encapsulates every rule of a decorative Lottie on a web page:
 *   - PNG poster fallback: shown until the animation is ready, and kept forever when JS is
 *     off, the player is missing, or fetch/parse fails (graceful degradation). Preferred
 *     form: a child <img> in the LIGHT DOM (reserves the box at HTML parse time — no layout
 *     jump before this script runs — and shows without any JS). The animation cross-fades
 *     over it once ready. The `poster` ATTRIBUTE (shadow background-image) stays supported
 *     for callers without a child img.
 *   - prefers-reduced-motion: no animation is ever loaded — the static poster stays.
 *   - IntersectionObserver: the JSON is fetched only when the element scrolls near the
 *     viewport, and the animation is paused while off-screen (CPU / battery).
 *   - aria-hidden: the element is treated as decorative.
 *   - optional max-loops: stop after N loops instead of an endless loop.
 *   - trigger="hover": play on pointer hover, pause on leave (default is autoplay). On touch
 *     devices (no hover) it falls back to autoplay so the animation is never stuck.
 *
 * Requires lottie-web (the global `lottie`, i.e. lottie.min.js) to be present on the page.
 * The check happens at play time, so the player script may load before OR after this file.
 *
 * Markup:
 *   <lottie-figure
 *       src="/media/deko/karte.json"     (required — URL of the Lottie JSON)
 *       poster="/media/deko/karte.png"   (legacy fallback — prefer the child <img> below)
 *       ratio="949/650"                  (recommended — from the server-side probe w/h;
 *                                         prevents layout shift before load)
 *       loop                             (optional — endless loop)
 *       max-loops="3"                    (optional — stop after N loops)
 *       trigger="hover"                  (optional — play on hover instead of autoplay)
 *       renderer="svg"                   (optional — svg (default) | canvas)
 *       hide-layers="Biel|Kanal">        (optional — top-level layer names (`nm`) dropped
 *                                         before rendering, e.g. text the page overlays
 *                                         as translatable HTML)
 *       <img src="/media/deko/karte.png" alt="" width="1" height="1">  (poster, light DOM)
 *   </lottie-figure>
 */
(function () {
  'use strict';
  if (typeof window === 'undefined' || !('customElements' in window)) { return; }
  if (customElements.get('lottie-figure')) { return; }

  var reducedMotion = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : { matches: false };

  function cssRatio(r) {
    // "949/650" or "949 650" or "1.46" -> a valid CSS aspect-ratio value.
    r = String(r).trim();
    if (r.indexOf('/') >= 0) { return r; }
    return r.replace(/\s+/, ' / ');
  }

  function cssUrl(u) {
    return "'" + String(u).replace(/['\\]/g, '\\$&') + "'";
  }

  var LottieFigure = function () {
    return Reflect.construct(HTMLElement, [], LottieFigure);
  };
  LottieFigure.prototype = Object.create(HTMLElement.prototype);
  LottieFigure.prototype.constructor = LottieFigure;

  LottieFigure.prototype.connectedCallback = function () {
    if (this._built) { return; }
    this._built = true;

    this.setAttribute('aria-hidden', 'true'); // decorative

    var ratio = this.getAttribute('ratio');
    var poster = this.getAttribute('poster') || '';
    var posterImg = this.querySelector(':scope > img'); // light-DOM poster (preferred)
    var root = this.attachShadow({ mode: 'open' });

    root.innerHTML =
      '<style>' +
        ':host{display:block;position:relative;width:100%}' +
        '.frame{position:relative;width:100%;overflow:hidden;' +
          'aspect-ratio:' + (ratio ? cssRatio(ratio) : '16 / 9') + '}' +
        '.poster{position:absolute;inset:0;background-position:center;' +
          'background-repeat:no-repeat;background-size:contain;' +
          'transition:opacity .4s ease}' +
        // The animation layer starts transparent and cross-fades in when ready, while the
        // poster (slotted img or legacy div) fades out — no hard swap.
        '.anim{position:absolute;inset:0;opacity:0;transition:opacity .4s ease}' +
        '.anim.is-ready{opacity:1}' +
        // The svg lottie injects carries width/height attributes = the composition size (e.g.
        // 800) and is only resized to the container a frame later. overflow:hidden then clips
        // that oversized first paint (looks zoomed) before it shrinks — a visible flash. Pin
        // the svg to the box from the start so it never renders at its native size.
        '.anim svg{display:block;width:100%!important;height:100%!important}' +
      '</style>' +
      '<div class="frame">' +
        (posterImg
          ? '<slot></slot>'
          : '<div class="poster"' +
              (poster ? ' style="background-image:url(' + cssUrl(poster) + ')"' : '') +
            '></div>') +
        '<div class="anim"></div>' +
      '</div>';

    if (posterImg) {
      // Pin the slotted poster to the frame. Inline styles so no page CSS (e.g. the
      // pre-upgrade `height:auto` reservation rule) can fight the overlay geometry.
      posterImg.style.cssText =
        'position:absolute;inset:0;width:100%;height:100%;object-fit:contain;' +
        'transition:opacity .4s ease';
      this._poster = posterImg;
    } else {
      this._poster = root.querySelector('.poster');
    }
    this._animBox = root.querySelector('.anim');

    var src = this.getAttribute('src');
    // No source, or the user asked for reduced motion -> keep the static poster, load nothing.
    if (!src || reducedMotion.matches) { return; }

    // trigger="hover": play on hover, not automatically. Only on devices that actually hover;
    // touch devices (hover: none) keep the normal autoplay-when-visible behaviour, else the
    // animation could never start there.
    var canHover = !window.matchMedia || window.matchMedia('(hover: hover)').matches;
    this._hover = this.getAttribute('trigger') === 'hover' && canHover;
    if (this._hover) {
      this.addEventListener('mouseenter', this._onEnter.bind(this));
      this.addEventListener('mouseleave', this._onLeave.bind(this));
    }

    if ('IntersectionObserver' in window) {
      this._io = new IntersectionObserver(this._onIntersect.bind(this), { rootMargin: '200px' });
      this._io.observe(this);
    } else {
      this._load(); // no observer available -> load eagerly
    }
  };

  LottieFigure.prototype.disconnectedCallback = function () {
    if (this._io) { this._io.disconnect(); this._io = null; }
    if (this._anim) { this._anim.destroy(); this._anim = null; }
  };

  LottieFigure.prototype._onIntersect = function (entries) {
    var e = entries[0];
    if (e.isIntersecting) {
      // Load when near the viewport. Auto-resume only in autoplay mode; in hover mode the
      // pointer drives play/pause, so being on-screen must not start it.
      if (!this._anim) { this._load(); }
      else if (!this._hover && this._playing === false) { this._anim.play(); this._playing = true; }
    } else if (this._anim && this._playing) {
      this._anim.pause();
      this._playing = false;
    }
  };

  // hover mode: enter -> play (loading first if the JSON isn't in yet), leave -> pause.
  LottieFigure.prototype._onEnter = function () {
    if (this._anim) {
      this._anim.play();
      this._playing = true;
    } else {
      this._wantPlay = true; // play as soon as the animation finishes loading
      this._load();
    }
  };

  LottieFigure.prototype._onLeave = function () {
    this._wantPlay = false;
    if (this._anim && this._playing) {
      this._anim.pause();
      this._playing = false;
    }
  };

  LottieFigure.prototype._load = function () {
    if (this._anim || this._loading) { return; }
    // Player missing (script blocked / not yet defined) -> keep poster, try again next time
    // the element intersects.
    if (typeof window.lottie === 'undefined') { return; }
    this._loading = true;

    var self = this;
    var src = this.getAttribute('src');
    var loop = this.hasAttribute('loop');
    var maxLoops = parseInt(this.getAttribute('max-loops'), 10);
    var renderer = this.getAttribute('renderer') || 'svg';

    fetch(src, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      })
      .then(function (data) {
        var hide = (self.getAttribute('hide-layers') || '').split('|').filter(Boolean);
        if (hide.length && Array.isArray(data.layers)) {
          data.layers = data.layers.filter(function (l) { return hide.indexOf(l.nm) < 0; });
        }
        self._anim = window.lottie.loadAnimation({
          container: self._animBox,
          renderer: renderer,
          loop: loop,
          autoplay: !self._hover, // hover mode: paused on the first frame until hovered
          animationData: data
        });
        self._playing = !self._hover;

        self._anim.addEventListener('DOMLoaded', function () {
          // Cross-fade: animation in, poster out (works for the slotted img and the legacy
          // background div alike — both carry an opacity transition).
          self._animBox.classList.add('is-ready');
          self._poster.style.opacity = '0';
          // Hover arrived while the JSON was still loading -> start now.
          if (self._hover && self._wantPlay) { self._anim.play(); self._playing = true; }
        });

        if (!isNaN(maxLoops) && maxLoops > 0) {
          var count = 0;
          self._anim.addEventListener('loopComplete', function () {
            if (++count >= maxLoops) { self._anim.pause(); self._playing = false; }
          });
        }
      })
      .catch(function () {
        // Any failure (offline, 404, invalid JSON) -> the poster simply stays.
        self._loading = false;
      });
  };

  customElements.define('lottie-figure', LottieFigure);
})();
