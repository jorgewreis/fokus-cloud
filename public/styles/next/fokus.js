var FokusStyles = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

  // packages/fokus-js/js/fokus.js
  var fokus_exports = {};
  __export(fokus_exports, {
    Accordion: () => Accordion,
    Breadcrumb: () => Breadcrumb,
    Carousel: () => Carousel,
    Collapse: () => Collapse,
    Combobox: () => Combobox,
    CommandPalette: () => CommandPalette,
    DataTable: () => DataTable,
    Datepicker: () => Datepicker,
    Dropdown: () => Dropdown,
    FileDrop: () => FileDrop,
    FileUploadAdvanced: () => FileUploadAdvanced,
    FormValidation: () => FormValidation,
    Modal: () => Modal,
    Navbar: () => Navbar,
    NestedMenu: () => NestedMenu,
    NotificationCenter: () => NotificationCenter,
    Offcanvas: () => Offcanvas,
    Pagination: () => Pagination,
    Popover: () => Popover,
    RangeSlider: () => RangeSlider,
    Scrollspy: () => Scrollspy,
    Select: () => Select,
    Spinner: () => Spinner,
    Stepper: () => Stepper,
    Tabs: () => Tabs,
    Tag: () => Tag,
    Theme: () => Theme,
    Toast: () => Toast,
    Tooltip: () => Tooltip,
    TreeView: () => TreeView,
    confirm: () => confirm,
    core: () => core_exports,
    createFocusTrap: () => createFocusTrap,
    getFocusableElements: () => getFocusableElements,
    onEscapeKey: () => onEscapeKey
  });

  // packages/fokus-js/js/core/index.js
  var core_exports = {};
  __export(core_exports, {
    applyPosition: () => applyPosition,
    autoInit: () => autoInit,
    cancelHeightTransition: () => cancelHeightTransition,
    collapse: () => collapse,
    computePosition: () => computePosition,
    createFocusTrap: () => createFocusTrap,
    createInstanceRegistry: () => createInstanceRegistry,
    expand: () => expand,
    getFocusableElements: () => getFocusableElements,
    isTopOverlay: () => isTopOverlay,
    lockScroll: () => lockScroll,
    onClickOutside: () => onClickOutside,
    onEscapeKey: () => onEscapeKey,
    onTransitionEnd: () => onTransitionEnd,
    registerOverlay: () => registerOverlay,
    unlockScroll: () => unlockScroll,
    unregisterOverlay: () => unregisterOverlay,
    updateFocusIsolation: () => updateFocusIsolation,
    watchPosition: () => watchPosition
  });

  // packages/fokus-js/js/core/positioning.js
  var OPPOSITE_PLACEMENT = {
    top: "bottom",
    bottom: "top",
    left: "right",
    right: "left"
  };
  function fitsPlacement(placement, referenceRect, floatingRect, offset, viewportWidth, viewportHeight) {
    switch (placement) {
      case "top":
        return referenceRect.top - floatingRect.height - offset >= 0;
      case "bottom":
        return referenceRect.bottom + floatingRect.height + offset <= viewportHeight;
      case "left":
        return referenceRect.left - floatingRect.width - offset >= 0;
      case "right":
        return referenceRect.right + floatingRect.width + offset <= viewportWidth;
      default:
        return true;
    }
  }
  function alignCrossAxis(align, referenceStart, referenceSize, floatingSize) {
    switch (align) {
      case "start":
        return referenceStart;
      case "end":
        return referenceStart + referenceSize - floatingSize;
      default:
        return referenceStart + (referenceSize - floatingSize) / 2;
    }
  }
  function computePosition(referenceEl, floatingEl, options = {}) {
    var _a;
    const { placement = "bottom", align = "center", offset = 8, padding = 8, fallbackPlacements } = options;
    const referenceRect = referenceEl.getBoundingClientRect();
    const floatingRect = floatingEl.getBoundingClientRect();
    const viewportWidth = document.documentElement.clientWidth;
    const viewportHeight = document.documentElement.clientHeight;
    const candidates = [...new Set(fallbackPlacements != null ? fallbackPlacements : [placement, OPPOSITE_PLACEMENT[placement], "bottom", "top", "right", "left"])].filter(Boolean);
    const placementCandidates = fallbackPlacements ? candidates : candidates.slice(0, 2);
    const finalPlacement = (_a = placementCandidates.find((candidate) => fitsPlacement(candidate, referenceRect, floatingRect, offset, viewportWidth, viewportHeight))) != null ? _a : placement;
    let top;
    let left;
    if (finalPlacement === "top" || finalPlacement === "bottom") {
      top = finalPlacement === "top" ? referenceRect.top - floatingRect.height - offset : referenceRect.bottom + offset;
      left = alignCrossAxis(align, referenceRect.left, referenceRect.width, floatingRect.width);
    } else {
      left = finalPlacement === "left" ? referenceRect.left - floatingRect.width - offset : referenceRect.right + offset;
      top = alignCrossAxis(align, referenceRect.top, referenceRect.height, floatingRect.height);
    }
    const maxLeft = Math.max(padding, viewportWidth - floatingRect.width - padding);
    const maxTop = Math.max(padding, viewportHeight - floatingRect.height - padding);
    left = Math.min(Math.max(left, padding), maxLeft);
    top = Math.min(Math.max(top, padding), maxTop);
    const crossSize = finalPlacement === "top" || finalPlacement === "bottom" ? floatingRect.width : floatingRect.height;
    const crossStart = finalPlacement === "top" || finalPlacement === "bottom" ? left : top;
    const referenceCenter = finalPlacement === "top" || finalPlacement === "bottom" ? referenceRect.left + referenceRect.width / 2 : referenceRect.top + referenceRect.height / 2;
    const crossOffset = Math.min(Math.max(referenceCenter - crossStart, 8), Math.max(8, crossSize - 8));
    return {
      top: top + window.scrollY,
      left: left + window.scrollX,
      placement: finalPlacement,
      arrowOffset: `${crossOffset}px`
    };
  }
  function watchPosition(referenceEl, floatingEl, options = {}) {
    const update = () => applyPosition(floatingEl, computePosition(referenceEl, floatingEl, options));
    let frame = null;
    const schedule = () => {
      if (frame !== null) return;
      frame = requestAnimationFrame(() => {
        frame = null;
        update();
      });
    };
    window.addEventListener("resize", schedule, { passive: true });
    window.addEventListener("scroll", schedule, { passive: true, capture: true });
    const observer = typeof ResizeObserver === "function" ? new ResizeObserver(schedule) : null;
    observer == null ? void 0 : observer.observe(referenceEl);
    observer == null ? void 0 : observer.observe(floatingEl);
    return () => {
      window.removeEventListener("resize", schedule);
      window.removeEventListener("scroll", schedule, true);
      if (frame !== null) cancelAnimationFrame(frame);
      observer == null ? void 0 : observer.disconnect();
    };
  }
  function applyPosition(floatingEl, position) {
    floatingEl.style.position = "absolute";
    const parent = floatingEl.offsetParent;
    const local = parent && parent !== document.body && parent !== document.documentElement;
    const rect = local ? parent.getBoundingClientRect() : { top: 0, left: 0 };
    floatingEl.style.top = `${position.top - (local ? rect.top + window.scrollY + parent.clientTop - parent.scrollTop : 0)}px`;
    floatingEl.style.left = `${position.left - (local ? rect.left + window.scrollX + parent.clientLeft - parent.scrollLeft : 0)}px`;
    if (position.arrowOffset) {
      floatingEl.style.setProperty("--fs-popover-arrow-offset", position.arrowOffset);
      floatingEl.style.setProperty("--fs-tooltip-arrow-offset", position.arrowOffset);
    }
  }

  // packages/fokus-js/js/core/overlay.js
  var lockCount = 0;
  var paddingCompensated = false;
  var previousOverflow = "";
  var previousPaddingRight = "";
  var activeOverlays = [];
  var overlayElements = /* @__PURE__ */ new WeakMap();
  var eventOverlays = /* @__PURE__ */ new WeakMap();
  function isTopOverlay(instance, event) {
    if (!event) return activeOverlays.at(-1) === instance;
    if (!eventOverlays.has(event)) eventOverlays.set(event, activeOverlays.at(-1));
    return eventOverlays.get(event) === instance;
  }
  function registerOverlay(instance, element) {
    if (element) overlayElements.set(instance, element);
    if (!activeOverlays.includes(instance)) activeOverlays.push(instance);
  }
  function unregisterOverlay(instance) {
    var _a;
    const element = overlayElements.get(instance);
    for (const child of [...activeOverlays].reverse()) {
      if (child !== instance && (element == null ? void 0 : element.contains(overlayElements.get(child)))) (_a = child.hide) == null ? void 0 : _a.call(child);
    }
    const wasTop = activeOverlays.at(-1) === instance;
    const index = activeOverlays.lastIndexOf(instance);
    if (index >= 0) activeOverlays.splice(index, 1);
    overlayElements.delete(instance);
    return wasTop;
  }
  function lockScroll() {
    lockCount += 1;
    if (lockCount > 1) return;
    const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
    previousOverflow = document.body.style.overflow;
    previousPaddingRight = document.body.style.paddingRight;
    document.body.style.overflow = "hidden";
    if (scrollbarWidth > 0) {
      document.body.style.paddingRight = `${scrollbarWidth}px`;
      paddingCompensated = true;
    }
  }
  function unlockScroll() {
    lockCount = Math.max(0, lockCount - 1);
    if (lockCount > 0) return;
    document.body.style.overflow = previousOverflow;
    if (paddingCompensated) {
      document.body.style.paddingRight = previousPaddingRight;
      paddingCompensated = false;
    }
  }
  function onClickOutside(el, callback) {
    const handler = (event) => {
      if (!el.contains(event.target)) callback(event);
    };
    document.addEventListener("click", handler, true);
    return () => {
      document.removeEventListener("click", handler, true);
    };
  }

  // packages/fokus-js/js/core/focus.js
  var FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  function getFocusableElements(container) {
    return Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR)).filter((el) => el.offsetParent !== null);
  }
  var traps = [];
  var isolated = [];
  function updateFocusIsolation(change = () => {
  }) {
    var _a;
    for (const { el, inert, inertAttribute, ariaHidden } of isolated) {
      el.inert = inert;
      if (inertAttribute === null) el.removeAttribute("inert");
      else el.setAttribute("inert", inertAttribute);
      if (ariaHidden === null) el.removeAttribute("aria-hidden");
      else el.setAttribute("aria-hidden", ariaHidden);
    }
    isolated = [];
    change();
    const container = (_a = traps.at(-1)) == null ? void 0 : _a.container;
    for (let node = container; (node == null ? void 0 : node.parentElement) && node !== document.body; node = node.parentElement) {
      for (const el of node.parentElement.children) {
        if (el === node) continue;
        isolated.push({ el, inert: el.inert, inertAttribute: el.getAttribute("inert"), ariaHidden: el.getAttribute("aria-hidden") });
        el.inert = true;
        el.setAttribute("inert", "");
        el.setAttribute("aria-hidden", "true");
      }
    }
  }
  function createFocusTrap(container) {
    let addedTabindex = false;
    const trap = { container };
    let returnFocus;
    const focusFirst = () => {
      const first = getFocusableElements(container)[0];
      if (first) first.focus();
      else {
        if (!container.hasAttribute("tabindex")) {
          container.setAttribute("tabindex", "-1");
          addedTabindex = true;
        }
        container.focus();
      }
    };
    trap.focusFirst = focusFirst;
    function handleKeydown(event) {
      if (event.key !== "Tab" || traps.at(-1) !== trap) return;
      const focusable = getFocusableElements(container);
      const first = focusable[0];
      const last = focusable.at(-1);
      if (!first) {
        event.preventDefault();
        focusFirst();
      } else if (event.shiftKey && (document.activeElement === first || document.activeElement === container)) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (document.activeElement === last || document.activeElement === container)) {
        event.preventDefault();
        first.focus();
      }
    }
    return {
      activate() {
        if (traps.includes(trap)) return;
        returnFocus = document.activeElement;
        container.addEventListener("keydown", handleKeydown);
        updateFocusIsolation(() => {
          traps.push(trap);
          focusFirst();
        });
      },
      deactivate() {
        const wasTop = traps.at(-1) === trap;
        container.removeEventListener("keydown", handleKeydown);
        updateFocusIsolation(() => {
          const index = traps.indexOf(trap);
          if (index >= 0) traps.splice(index, 1);
          const active = traps.at(-1);
          if (wasTop && active) {
            if ((returnFocus == null ? void 0 : returnFocus.isConnected) && active.container.contains(returnFocus)) returnFocus.focus();
            else active.focusFirst();
          }
        });
        if (addedTabindex) {
          container.removeAttribute("tabindex");
          addedTabindex = false;
        }
      }
    };
  }
  function onEscapeKey(callback) {
    const handler = (event) => {
      if (event.key === "Escape") callback(event);
    };
    document.addEventListener("keydown", handler);
    return () => document.removeEventListener("keydown", handler);
  }

  // packages/fokus-js/js/core/transition.js
  function prefersReducedMotion() {
    return window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }
  var heightTransitions = /* @__PURE__ */ new WeakMap();
  function cancelHeightTransition(el) {
    var _a;
    (_a = heightTransitions.get(el)) == null ? void 0 : _a.cancel();
  }
  function animateHeight(el, opening, options) {
    var _a;
    cancelHeightTransition(el);
    let frame;
    let timeout;
    let resolve;
    const done = new Promise((finish2) => {
      resolve = finish2;
    });
    const cleanup = () => {
      cancelAnimationFrame(frame);
      clearTimeout(timeout);
      el.removeEventListener("transitionend", handleEnd);
      el.style.removeProperty("height");
      el.style.removeProperty("overflow");
      el.style.removeProperty("transition");
    };
    const state = {
      cancel() {
        cleanup();
        heightTransitions.delete(el);
        resolve(false);
      }
    };
    const finish = () => {
      if (heightTransitions.get(el) !== state) return;
      cleanup();
      if (!opening) el.style.display = "none";
      heightTransitions.delete(el);
      resolve(true);
    };
    const handleEnd = (event) => {
      if (event.target === el && event.propertyName === "height") finish();
    };
    heightTransitions.set(el, state);
    if (opening) el.style.display = "";
    if (prefersReducedMotion()) {
      if (!opening) el.style.display = "none";
      Promise.resolve().then(finish);
      return done;
    }
    const duration = (_a = options.duration) != null ? _a : 200;
    const height = `${el.scrollHeight}px`;
    el.style.overflow = "hidden";
    el.style.height = opening ? "0px" : height;
    el.style.transition = `height ${duration}ms ease`;
    el.addEventListener("transitionend", handleEnd);
    timeout = window.setTimeout(finish, Math.max(450, duration + 50));
    frame = requestAnimationFrame(() => {
      frame = requestAnimationFrame(() => {
        if (heightTransitions.get(el) === state) el.style.height = opening ? height : "0px";
      });
    });
    return done;
  }
  function collapse(el, options = {}) {
    return animateHeight(el, false, options);
  }
  function expand(el, options = {}) {
    return animateHeight(el, true, options);
  }
  function onTransitionEnd(el, propertyName) {
    return new Promise((resolve) => {
      const handleEnd = (event) => {
        if (event.target !== el) return;
        if (propertyName && event.propertyName !== propertyName) return;
        el.removeEventListener("transitionend", handleEnd);
        resolve();
      };
      el.addEventListener("transitionend", handleEnd);
    });
  }

  // packages/fokus-js/js/core/register.js
  function createInstanceRegistry() {
    const instances31 = /* @__PURE__ */ new WeakMap();
    return {
      get(el) {
        return instances31.get(el);
      },
      set(el, instance) {
        instances31.set(el, instance);
      },
      delete(el) {
        instances31.delete(el);
      }
    };
  }
  function autoInit(name, Ctor, options = {}) {
    var _a;
    if (typeof document === "undefined") return;
    const selector = (_a = options.selector) != null ? _a : `[data-fs="${name}"]`;
    const init = () => {
      document.querySelectorAll(selector).forEach((el) => {
        if (!Ctor.getInstance(el)) new Ctor(el);
      });
    };
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", init);
    } else {
      init();
    }
  }

  // packages/fokus-js/js/core/theme.js
  function applyThemeContext(targetEl, sourceEl) {
    targetEl.removeAttribute("data-theme");
    targetEl.removeAttribute("data-fs-theme");
    const root = sourceEl.closest("[data-fs-theme], [data-theme]");
    const themeAttr = (root == null ? void 0 : root.hasAttribute("data-fs-theme")) ? "data-fs-theme" : "data-theme";
    const theme = root == null ? void 0 : root.getAttribute(themeAttr);
    if (theme) targetEl.setAttribute(themeAttr, theme);
  }

  // packages/fokus-js/js/core/portal.js
  function createPortal(element, reference) {
    const parent = element.parentNode;
    const next = element.nextSibling;
    return {
      mount() {
        var _a;
        const root = (_a = reference.closest('.fs-modal-dialog, .fs-offcanvas, .fs-command-palette-dialog, [role="dialog"]')) != null ? _a : document.body;
        if (element.parentNode !== root) updateFocusIsolation(() => root.appendChild(element));
      },
      restore() {
        if (parent) updateFocusIsolation(() => parent.insertBefore(element, (next == null ? void 0 : next.parentNode) === parent ? next : null));
      }
    };
  }

  // packages/fokus-js/js/dropdown.js
  var instances = createInstanceRegistry();
  var Dropdown = class _Dropdown {
    constructor(toggleEl, options = {}) {
      var _a, _b, _c, _d;
      const targetSelector = toggleEl.getAttribute("data-fs-target");
      const menuEl = targetSelector ? document.querySelector(targetSelector) : toggleEl.nextElementSibling;
      if (!menuEl) {
        throw new Error("FokusStyles.Dropdown: elemento do menu n\xE3o encontrado (data-fs-target).");
      }
      this.toggleEl = toggleEl;
      this.menuEl = menuEl;
      this.placement = (_b = (_a = options.placement) != null ? _a : toggleEl.getAttribute("data-placement")) != null ? _b : "bottom";
      this.align = (_d = (_c = options.align) != null ? _c : toggleEl.getAttribute("data-align")) != null ? _d : "start";
      this.isOpen = false;
      this._outsideClickCleanup = null;
      this._positionCleanup = null;
      this._portal = createPortal(this.menuEl, this.toggleEl);
      this._portal.mount();
      if (!this.toggleEl.id) this.toggleEl.id = `fs-dropdown-toggle-${Math.random().toString(36).slice(2)}`;
      this.toggleEl.setAttribute("aria-haspopup", "menu");
      this.toggleEl.setAttribute("aria-expanded", "false");
      this.toggleEl.setAttribute("aria-controls", this.menuEl.id || `fs-dropdown-${Math.random().toString(36).slice(2)}`);
      if (!this.menuEl.id) this.menuEl.id = this.toggleEl.getAttribute("aria-controls");
      if (!this.menuEl.hasAttribute("role")) this.menuEl.setAttribute("role", "menu");
      this.menuEl.setAttribute("aria-orientation", "vertical");
      this.menuEl.setAttribute("aria-labelledby", this.toggleEl.id || this.toggleEl.getAttribute("aria-controls"));
      this.menuEl.querySelectorAll(".fs-dropdown-item").forEach((item) => {
        if (!item.hasAttribute("role")) item.setAttribute("role", "menuitem");
        if (this._isDisabled(item)) item.setAttribute("aria-disabled", "true");
      });
      this.menuEl.querySelectorAll(".fs-dropdown-divider").forEach((divider) => divider.setAttribute("role", "separator"));
      this.menuEl.querySelectorAll(".fs-dropdown-header").forEach((header) => header.setAttribute("role", "presentation"));
      this._handleToggleClick = this._handleToggleClick.bind(this);
      this._handleToggleKeydown = this._handleToggleKeydown.bind(this);
      this._handleMenuKeydown = this._handleMenuKeydown.bind(this);
      this._handleMenuClick = this._handleMenuClick.bind(this);
      this.toggleEl.addEventListener("click", this._handleToggleClick);
      this.toggleEl.addEventListener("keydown", this._handleToggleKeydown);
      this.menuEl.addEventListener("keydown", this._handleMenuKeydown);
      this.menuEl.addEventListener("click", this._handleMenuClick);
      this._removeEscapeListener = onEscapeKey((event) => {
        if (this.isOpen && isTopOverlay(this, event)) {
          this.hide();
          this.toggleEl.focus();
        }
      });
      instances.set(toggleEl, this);
    }
    static getInstance(el) {
      return instances.get(el);
    }
    static getOrCreateInstance(el, options = {}) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _Dropdown(el, options);
    }
    static show(el, options = {}) {
      return this.getOrCreateInstance(el, options).show();
    }
    static hide(el) {
      var _a;
      return (_a = this.getInstance(el)) == null ? void 0 : _a.hide();
    }
    static toggle(el, options = {}) {
      return this.getOrCreateInstance(el, options).toggle();
    }
    _handleToggleClick(event) {
      event.preventDefault();
      this.toggle();
    }
    _handleToggleKeydown(event) {
      var _a;
      if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
      event.preventDefault();
      if (!this.isOpen) this.show();
      const items = this._items();
      (_a = event.key === "ArrowUp" ? items.at(-1) : items[0]) == null ? void 0 : _a.focus();
    }
    _handleMenuClick(event) {
      if (event.target.closest(".fs-dropdown-item, [data-fs-dropdown-dismiss]")) {
        this.hide();
        this.toggleEl.focus();
      }
    }
    _handleMenuKeydown(event) {
      var _a, _b, _c, _d;
      const items = this._items();
      const currentIndex = items.indexOf(document.activeElement);
      if (event.key === "ArrowDown") {
        event.preventDefault();
        (_a = items[(currentIndex + 1) % items.length]) == null ? void 0 : _a.focus();
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        (_b = items[(currentIndex - 1 + items.length) % items.length]) == null ? void 0 : _b.focus();
      } else if (event.key === "Home") {
        event.preventDefault();
        (_c = items[0]) == null ? void 0 : _c.focus();
      } else if (event.key === "End") {
        event.preventDefault();
        (_d = items[items.length - 1]) == null ? void 0 : _d.focus();
      } else if (event.key === "Escape") {
        event.preventDefault();
        event.stopPropagation();
        this.hide();
        this.toggleEl.focus();
      }
    }
    show() {
      if (this.isOpen) return;
      this.isOpen = true;
      this._portal.mount();
      registerOverlay(this, this.menuEl);
      applyThemeContext(this.menuEl, this.toggleEl);
      this.menuEl.classList.add("is-open");
      const position = computePosition(this.toggleEl, this.menuEl, {
        placement: this.placement,
        align: this.align,
        offset: 4
      });
      applyPosition(this.menuEl, position);
      this.menuEl.dataset.placement = position.placement;
      this.menuEl.dataset.align = this.align;
      this._positionCleanup = watchPosition(this.toggleEl, this.menuEl, {
        placement: this.placement,
        align: this.align,
        offset: 4
      });
      this.toggleEl.setAttribute("aria-expanded", "true");
      const firstItem = this._items()[0];
      firstItem == null ? void 0 : firstItem.focus();
      this._outsideClickCleanup = onClickOutside(this.menuEl, (event) => {
        if (!isTopOverlay(this, event) || this.toggleEl.contains(event.target)) return;
        this.hide();
      });
      this.toggleEl.dispatchEvent(new CustomEvent("fs:shown", { bubbles: true }));
      this.toggleEl.dispatchEvent(new CustomEvent("fs:dropdown:shown", { bubbles: true }));
    }
    hide() {
      var _a, _b;
      if (!this.isOpen) return;
      this.isOpen = false;
      unregisterOverlay(this);
      this.menuEl.classList.remove("is-open");
      this.menuEl.style.removeProperty("position");
      this.menuEl.style.removeProperty("top");
      this.menuEl.style.removeProperty("left");
      delete this.menuEl.dataset.placement;
      delete this.menuEl.dataset.align;
      this.toggleEl.setAttribute("aria-expanded", "false");
      (_a = this._outsideClickCleanup) == null ? void 0 : _a.call(this);
      this._outsideClickCleanup = null;
      (_b = this._positionCleanup) == null ? void 0 : _b.call(this);
      this._positionCleanup = null;
      this.toggleEl.dispatchEvent(new CustomEvent("fs:hidden", { bubbles: true }));
      this.toggleEl.dispatchEvent(new CustomEvent("fs:dropdown:hidden", { bubbles: true }));
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    _isDisabled(item) {
      return item.classList.contains("is-disabled") || item.disabled || item.getAttribute("aria-disabled") === "true";
    }
    _items() {
      return Array.from(this.menuEl.querySelectorAll(".fs-dropdown-item")).filter((item) => !this._isDisabled(item));
    }
    dispose() {
      this.hide();
      this._removeEscapeListener();
      this.toggleEl.removeEventListener("click", this._handleToggleClick);
      this.toggleEl.removeEventListener("keydown", this._handleToggleKeydown);
      this.menuEl.removeEventListener("keydown", this._handleMenuKeydown);
      this.menuEl.removeEventListener("click", this._handleMenuClick);
      this._portal.restore();
      instances.delete(this.toggleEl);
    }
  };
  autoInit("dropdown", Dropdown);

  // packages/fokus-js/js/tooltip.js
  var instances2 = createInstanceRegistry();
  var idCounter = 0;
  var openInstance = null;
  var DEFAULT_TOOLTIP_OFFSET = 8;
  function toDelay(value, fallback = 0) {
    if (value === null || value === void 0 || value === "") return fallback;
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed >= 0 ? parsed : fallback;
  }
  function parsePlacement(value = "top") {
    const [placement, align = "center"] = value.split("-");
    return { placement, align: ["start", "center", "end"].includes(align) ? align : "center" };
  }
  var Tooltip = class {
    constructor(referenceEl, options = {}) {
      var _a, _b, _c, _d, _e, _f, _g, _h, _i;
      const title = (_b = (_a = referenceEl.getAttribute("title")) != null ? _a : referenceEl.getAttribute("data-title")) != null ? _b : options.title;
      if (!title) {
        throw new Error("FokusStyles.Tooltip: elemento sem texto (atributo title/data-title).");
      }
      referenceEl.removeAttribute("title");
      idCounter += 1;
      this.referenceEl = referenceEl;
      this.title = title;
      const parsedPlacement = parsePlacement((_d = (_c = options.placement) != null ? _c : referenceEl.getAttribute("data-placement")) != null ? _d : "top");
      this.placement = parsedPlacement.placement;
      this.align = (_f = (_e = options.align) != null ? _e : referenceEl.getAttribute("data-align")) != null ? _f : parsedPlacement.align;
      this.offset = toDelay((_g = options.offset) != null ? _g : referenceEl.getAttribute("data-offset"), DEFAULT_TOOLTIP_OFFSET);
      this.showDelay = toDelay((_h = options.showDelay) != null ? _h : referenceEl.getAttribute("data-show-delay"), 0);
      this.hideDelay = toDelay((_i = options.hideDelay) != null ? _i : referenceEl.getAttribute("data-hide-delay"));
      this.isOpen = false;
      this.id = `fokus-tooltip-${idCounter}`;
      this._positionCleanup = null;
      this._showTimer = null;
      this._hideTimer = null;
      this._touchOpened = false;
      this._originalDescribedBy = referenceEl.getAttribute("aria-describedby");
      this.tooltipEl = document.createElement("div");
      this.tooltipEl.className = "fs-tooltip";
      this.tooltipEl.id = this.id;
      this.tooltipEl.setAttribute("role", "tooltip");
      this.tooltipEl.innerHTML = '<div class="fs-tooltip-arrow"></div><div class="fs-tooltip-inner"></div>';
      this.tooltipEl.querySelector(".fs-tooltip-inner").textContent = this.title;
      this._portal = createPortal(this.tooltipEl, this.referenceEl);
      this._portal.mount();
      this._addDescribedBy();
      this._handleShow = () => this._scheduleShow(true);
      this._handleHide = () => this._scheduleHide();
      this._handlePointerEnter = () => this._scheduleShow();
      this._handlePointerLeave = () => this._scheduleHide();
      this._handlePointerDown = (event) => {
        if (event.pointerType === "touch") {
          if (this._touchOpened && this.isOpen) {
            this.hideImmediate();
            return;
          }
          this._touchOpened = true;
          this._scheduleShow(true);
        }
      };
      referenceEl.addEventListener("pointerenter", this._handlePointerEnter);
      referenceEl.addEventListener("pointerleave", this._handlePointerLeave);
      referenceEl.addEventListener("pointerdown", this._handlePointerDown);
      referenceEl.addEventListener("mouseenter", this._handlePointerEnter);
      referenceEl.addEventListener("mouseleave", this._handlePointerLeave);
      referenceEl.addEventListener("focus", this._handleShow);
      referenceEl.addEventListener("blur", this._handleHide);
      this._handleDocumentPointerDown = (event) => {
        if (this._touchOpened && !this.referenceEl.contains(event.target) && !this.tooltipEl.contains(event.target)) this.hide();
      };
      document.addEventListener("pointerdown", this._handleDocumentPointerDown, true);
      this._removeEscapeListener = onEscapeKey((event) => {
        if (this.isOpen && isTopOverlay(this, event)) this.hide();
      });
      instances2.set(referenceEl, this);
    }
    static getInstance(el) {
      return instances2.get(el);
    }
    _clearTimers() {
      clearTimeout(this._showTimer);
      clearTimeout(this._hideTimer);
      this._showTimer = null;
      this._hideTimer = null;
    }
    _addDescribedBy() {
      var _a;
      const ids = new Set(((_a = this.referenceEl.getAttribute("aria-describedby")) != null ? _a : "").split(/\s+/).filter(Boolean));
      ids.add(this.id);
      this.referenceEl.setAttribute("aria-describedby", [...ids].join(" "));
    }
    _scheduleShow(immediate = false) {
      this._clearTimers();
      if (this.isOpen) return;
      const delay = immediate ? 0 : this.showDelay;
      if (delay === 0) {
        this.show();
        return;
      }
      this._showTimer = setTimeout(() => this.show(), delay);
    }
    _scheduleHide(immediate = false) {
      this._clearTimers();
      if (!this.isOpen) return;
      const delay = immediate ? 0 : this.hideDelay;
      if (delay === 0) {
        this.hide();
        return;
      }
      this._hideTimer = setTimeout(() => this.hide(), delay);
    }
    show() {
      this._clearTimers();
      if (this.isOpen) return;
      if (openInstance && openInstance !== this) openInstance.hide(true);
      openInstance = this;
      this.isOpen = true;
      this._portal.mount();
      registerOverlay(this, this.tooltipEl);
      applyThemeContext(this.tooltipEl, this.referenceEl);
      this.tooltipEl.classList.add("is-open");
      const position = computePosition(this.referenceEl, this.tooltipEl, { placement: this.placement, align: this.align, offset: this.offset });
      applyPosition(this.tooltipEl, position);
      this._positionCleanup = watchPosition(this.referenceEl, this.tooltipEl, {
        placement: this.placement,
        align: this.align,
        offset: this.offset
      });
      this.tooltipEl.setAttribute("data-placement", position.placement);
      this.tooltipEl.setAttribute("data-align", this.align);
      this.referenceEl.dispatchEvent(new CustomEvent("fs:tooltip:shown", { bubbles: true }));
    }
    hide() {
      this.hideImmediate();
    }
    hideImmediate() {
      var _a;
      this._clearTimers();
      if (!this.isOpen) return;
      this.isOpen = false;
      unregisterOverlay(this);
      this._touchOpened = false;
      this.tooltipEl.classList.remove("is-open");
      (_a = this._positionCleanup) == null ? void 0 : _a.call(this);
      this._positionCleanup = null;
      this.referenceEl.dispatchEvent(new CustomEvent("fs:tooltip:hidden", { bubbles: true }));
      if (openInstance === this) openInstance = null;
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      this.hideImmediate();
      this._clearTimers();
      this.referenceEl.removeEventListener("pointerenter", this._handlePointerEnter);
      this.referenceEl.removeEventListener("pointerleave", this._handlePointerLeave);
      this.referenceEl.removeEventListener("pointerdown", this._handlePointerDown);
      this.referenceEl.removeEventListener("mouseenter", this._handlePointerEnter);
      this.referenceEl.removeEventListener("mouseleave", this._handlePointerLeave);
      this.referenceEl.removeEventListener("focus", this._handleShow);
      this.referenceEl.removeEventListener("blur", this._handleHide);
      document.removeEventListener("pointerdown", this._handleDocumentPointerDown, true);
      this._removeEscapeListener();
      if (this._originalDescribedBy === null) this.referenceEl.removeAttribute("aria-describedby");
      else this.referenceEl.setAttribute("aria-describedby", this._originalDescribedBy);
      this.tooltipEl.remove();
      instances2.delete(this.referenceEl);
    }
  };
  autoInit("tooltip", Tooltip);

  // packages/fokus-js/js/popover.js
  var instances3 = createInstanceRegistry();
  var idCounter2 = 0;
  var HOVER_HIDE_DELAY = 100;
  var openPopovers = /* @__PURE__ */ new Set();
  var Popover = class {
    constructor(triggerEl, options = {}) {
      var _a, _b, _c, _d, _e, _f;
      const targetSelector = triggerEl.getAttribute("data-fs-target");
      const popoverEl = targetSelector ? document.querySelector(targetSelector) : null;
      if (!popoverEl) {
        throw new Error("FokusStyles.Popover: elemento n\xE3o encontrado (data-fs-target).");
      }
      this.triggerEl = triggerEl;
      this.popoverEl = popoverEl;
      this.placement = (_b = (_a = options.placement) != null ? _a : triggerEl.getAttribute("data-placement")) != null ? _b : "top";
      this.align = (_d = (_c = options.align) != null ? _c : triggerEl.getAttribute("data-align")) != null ? _d : "center";
      this.trigger = (_f = (_e = options.trigger) != null ? _e : triggerEl.getAttribute("data-trigger")) != null ? _f : "click";
      this.isOpen = false;
      this._outsideClickCleanup = null;
      this._removeEscapeListener = null;
      this._hideTimer = null;
      this._suppressNextFocus = false;
      this._positionCleanup = null;
      this._portal = createPortal(this.popoverEl, this.triggerEl);
      this._portal.mount();
      popoverEl.setAttribute("role", "dialog");
      popoverEl.setAttribute("aria-modal", "false");
      idCounter2 += 1;
      if (!popoverEl.id) popoverEl.id = `fokus-popover-${idCounter2}`;
      const headerEl = popoverEl.querySelector(".fs-popover-header");
      if (headerEl) {
        if (!headerEl.id) headerEl.id = `fokus-popover-header-${idCounter2}`;
        popoverEl.setAttribute("aria-labelledby", headerEl.id);
      }
      const descriptionEl = popoverEl.querySelector(".fs-popover-text");
      if (descriptionEl) {
        if (!descriptionEl.id) descriptionEl.id = `fokus-popover-text-${idCounter2}`;
        popoverEl.setAttribute("aria-describedby", descriptionEl.id);
      }
      triggerEl.setAttribute("aria-controls", popoverEl.id);
      triggerEl.setAttribute("aria-expanded", "false");
      this._handleDismissClick = this._handleDismissClick.bind(this);
      popoverEl.addEventListener("click", this._handleDismissClick);
      this._bindTrigger();
      instances3.set(triggerEl, this);
    }
    static getInstance(el) {
      return instances3.get(el);
    }
    _bindTrigger() {
      if (this.trigger === "manual") return;
      if (this.trigger === "click") {
        this._handleTriggerClick = (event) => {
          event.preventDefault();
          this.toggle();
        };
        this.triggerEl.addEventListener("click", this._handleTriggerClick);
      } else if (this.trigger === "hover") {
        this._handleMouseEnter = () => {
          clearTimeout(this._hideTimer);
          this.show();
        };
        this._handleMouseLeave = () => {
          this._hideTimer = setTimeout(() => this.hide(), HOVER_HIDE_DELAY);
        };
        this.triggerEl.addEventListener("mouseenter", this._handleMouseEnter);
        this.triggerEl.addEventListener("mouseleave", this._handleMouseLeave);
        this.popoverEl.addEventListener("mouseenter", this._handleMouseEnter);
        this.popoverEl.addEventListener("mouseleave", this._handleMouseLeave);
      } else if (this.trigger === "focus") {
        this._handleTriggerFocus = () => {
          if (this._suppressNextFocus) {
            this._suppressNextFocus = false;
            return;
          }
          this.show();
        };
        this._handleTriggerFocusOut = (event) => {
          if (this.popoverEl.contains(event.relatedTarget)) return;
          this.hide();
        };
        this._handlePopoverFocusOut = (event) => {
          if (this.triggerEl.contains(event.relatedTarget) || this.popoverEl.contains(event.relatedTarget)) return;
          this.hide();
        };
        this.triggerEl.addEventListener("focus", this._handleTriggerFocus);
        this.triggerEl.addEventListener("focusout", this._handleTriggerFocusOut);
        this.popoverEl.addEventListener("focusout", this._handlePopoverFocusOut);
      }
      if (this.trigger !== "manual") {
        this._removeEscapeListener = onEscapeKey((event) => {
          if (!this.isOpen || !isTopOverlay(this, event)) return;
          this.hide();
          this.triggerEl.focus();
        });
      }
    }
    _handleDismissClick(event) {
      if (event.target.closest('[data-fs-dismiss="popover"]')) {
        const restoreFocus = this.popoverEl.contains(document.activeElement);
        if (restoreFocus && this.trigger === "focus") this._suppressNextFocus = true;
        this.hide();
        if (restoreFocus && typeof this.triggerEl.focus === "function") {
          this.triggerEl.focus();
        }
      }
    }
    show() {
      if (this.isOpen) return;
      this.isOpen = true;
      this._portal.mount();
      registerOverlay(this, this.popoverEl);
      applyThemeContext(this.popoverEl, this.triggerEl);
      this.popoverEl.classList.add("is-open");
      openPopovers.forEach((popover) => {
        if (popover !== this) popover.hide();
      });
      const position = computePosition(this.triggerEl, this.popoverEl, {
        placement: this.placement,
        align: this.align,
        offset: 10
      });
      applyPosition(this.popoverEl, position);
      this._positionCleanup = watchPosition(this.triggerEl, this.popoverEl, {
        placement: this.placement,
        align: this.align,
        offset: 10
      });
      this.popoverEl.setAttribute("data-placement", position.placement);
      openPopovers.add(this);
      this.triggerEl.setAttribute("aria-expanded", "true");
      if (this.trigger === "click") {
        this._outsideClickCleanup = onClickOutside(this.popoverEl, (event) => {
          if (!isTopOverlay(this, event) || this.triggerEl.contains(event.target)) return;
          this.hide();
        });
      }
      this.triggerEl.dispatchEvent(new CustomEvent("fs:popover:shown", { bubbles: true }));
    }
    hide() {
      var _a, _b;
      if (!this.isOpen) return;
      this.isOpen = false;
      unregisterOverlay(this);
      openPopovers.delete(this);
      this.popoverEl.classList.remove("is-open");
      (_a = this._positionCleanup) == null ? void 0 : _a.call(this);
      this._positionCleanup = null;
      this.triggerEl.setAttribute("aria-expanded", "false");
      (_b = this._outsideClickCleanup) == null ? void 0 : _b.call(this);
      this._outsideClickCleanup = null;
      this.triggerEl.dispatchEvent(new CustomEvent("fs:popover:hidden", { bubbles: true }));
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      var _a;
      this.hide();
      clearTimeout(this._hideTimer);
      (_a = this._removeEscapeListener) == null ? void 0 : _a.call(this);
      this.popoverEl.removeEventListener("click", this._handleDismissClick);
      this.triggerEl.removeEventListener("click", this._handleTriggerClick);
      this.triggerEl.removeEventListener("mouseenter", this._handleMouseEnter);
      this.triggerEl.removeEventListener("mouseleave", this._handleMouseLeave);
      this.popoverEl.removeEventListener("mouseenter", this._handleMouseEnter);
      this.popoverEl.removeEventListener("mouseleave", this._handleMouseLeave);
      this.triggerEl.removeEventListener("focus", this._handleTriggerFocus);
      this.triggerEl.removeEventListener("focusout", this._handleTriggerFocusOut);
      this.popoverEl.removeEventListener("focusout", this._handlePopoverFocusOut);
      this._portal.restore();
      instances3.delete(this.triggerEl);
    }
  };
  autoInit("popover", Popover);

  // packages/fokus-js/js/modal.js
  var instances4 = createInstanceRegistry();
  var Modal = class _Modal {
    constructor(triggerEl, options = {}) {
      var _a, _b;
      const targetSelector = triggerEl.getAttribute("data-fs-target");
      const modalEl = targetSelector ? document.querySelector(targetSelector) : null;
      if (!modalEl) {
        throw new Error("FokusStyles.Modal: elemento do modal n\xE3o encontrado (data-fs-target).");
      }
      this.triggerEl = triggerEl;
      this.modalEl = modalEl;
      this.dialogEl = (_a = modalEl.querySelector(".fs-modal-dialog")) != null ? _a : modalEl;
      this.staticBackdrop = ((_b = options.backdrop) != null ? _b : modalEl.getAttribute("data-backdrop")) === "static";
      this.isOpen = false;
      this._focusTrap = null;
      this._outsideClickCleanup = null;
      modalEl.setAttribute("role", "dialog");
      modalEl.setAttribute("aria-modal", "true");
      modalEl.setAttribute("aria-hidden", "true");
      const titleEl = modalEl.querySelector(".fs-modal-title");
      if (titleEl) {
        if (!titleEl.id) titleEl.id = `fs-modal-title-${Math.random().toString(36).slice(2)}`;
        modalEl.setAttribute("aria-labelledby", titleEl.id);
      }
      this._handleTriggerClick = this._handleTriggerClick.bind(this);
      this._handleDismissClick = this._handleDismissClick.bind(this);
      triggerEl.addEventListener("click", this._handleTriggerClick);
      modalEl.addEventListener("click", this._handleDismissClick);
      this._removeEscapeListener = onEscapeKey((event) => {
        if (this.isOpen && isTopOverlay(this, event) && !this.staticBackdrop) this.hide();
      });
      instances4.set(triggerEl, this);
    }
    static getInstance(el) {
      return instances4.get(el);
    }
    static getOrCreateInstance(el, options = {}) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _Modal(el, options);
    }
    static show(el, options = {}) {
      return this.getOrCreateInstance(el, options).show();
    }
    static hide(el) {
      var _a;
      return (_a = this.getInstance(el)) == null ? void 0 : _a.hide();
    }
    static toggle(el, options = {}) {
      return this.getOrCreateInstance(el, options).toggle();
    }
    _handleTriggerClick(event) {
      event.preventDefault();
      this.show();
    }
    _handleDismissClick(event) {
      if (isTopOverlay(this, event) && event.target.closest('[data-fs-dismiss="modal"]')) {
        this.hide();
      }
    }
    show() {
      if (this.isOpen) return;
      this.triggerEl.dispatchEvent(new CustomEvent("fs:show", { bubbles: true }));
      this.isOpen = true;
      registerOverlay(this, this.dialogEl);
      this.modalEl.classList.add("is-open");
      updateFocusIsolation(() => this.modalEl.setAttribute("aria-hidden", "false"));
      lockScroll();
      this._focusTrap = createFocusTrap(this.dialogEl);
      this._focusTrap.activate();
      if (!this.staticBackdrop) {
        this._outsideClickCleanup = onClickOutside(this.dialogEl, (event) => {
          if (isTopOverlay(this, event)) this.hide();
        });
      }
      this.triggerEl.dispatchEvent(new CustomEvent("fs:shown", { bubbles: true }));
      this.triggerEl.dispatchEvent(new CustomEvent("fs:modal:shown", { bubbles: true }));
    }
    hide() {
      var _a, _b;
      if (!this.isOpen) return;
      this.triggerEl.dispatchEvent(new CustomEvent("fs:hide", { bubbles: true }));
      this.isOpen = false;
      const wasTop = unregisterOverlay(this);
      (_a = this._focusTrap) == null ? void 0 : _a.deactivate();
      this._focusTrap = null;
      if (wasTop && this.triggerEl.isConnected && !this.triggerEl.closest("[inert]")) this.triggerEl.focus();
      this.modalEl.classList.remove("is-open");
      updateFocusIsolation(() => this.modalEl.setAttribute("aria-hidden", "true"));
      unlockScroll();
      (_b = this._outsideClickCleanup) == null ? void 0 : _b.call(this);
      this._outsideClickCleanup = null;
      this.triggerEl.dispatchEvent(new CustomEvent("fs:hidden", { bubbles: true }));
      this.triggerEl.dispatchEvent(new CustomEvent("fs:modal:hidden", { bubbles: true }));
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      this.hide();
      this._removeEscapeListener();
      this.triggerEl.removeEventListener("click", this._handleTriggerClick);
      this.modalEl.removeEventListener("click", this._handleDismissClick);
      instances4.delete(this.triggerEl);
    }
  };
  autoInit("modal", Modal, { selector: '[data-fs="modal"], [data-fs-toggle="modal"]' });

  // packages/fokus-js/js/core/html.js
  function escapeHtml(value) {
    const div = document.createElement("div");
    div.textContent = value == null ? "" : String(value);
    return div.innerHTML;
  }

  // packages/fokus-js/js/core/variant.js
  var variants = /* @__PURE__ */ new Set(["primary", "secondary", "success", "warning", "danger", "info"]);
  function normalizeVariant(value, fallback = "primary") {
    return variants.has(value) ? value : fallback;
  }

  // packages/fokus-js/js/confirm.js
  var idCounter3 = 0;
  function confirm(options = {}) {
    const { title = "Tem certeza?", message = "", confirmText = "Confirmar", cancelText = "Cancelar", variant = "danger" } = options;
    return new Promise((resolve) => {
      idCounter3 += 1;
      const modalId = `fokus-confirm-${idCounter3}`;
      const invokerEl = document.activeElement instanceof HTMLElement ? document.activeElement : document.body;
      const modalEl = document.createElement("div");
      modalEl.className = "fs-modal fs-alert-dialog";
      modalEl.id = modalId;
      modalEl.innerHTML = `
      <div class="fs-modal-dialog fs-modal-sm">
        <div class="fs-modal-content">
          <div class="fs-modal-body">
            <h3 class="fs-modal-title">${escapeHtml(title)}</h3>
            ${message ? `<p class="fs-alert-dialog-message">${escapeHtml(message)}</p>` : ""}
          </div>
          <div class="fs-modal-footer">
            <button type="button" class="fs-btn" data-action="cancel">${escapeHtml(cancelText)}</button>
            <button type="button" class="fs-btn fs-btn-${normalizeVariant(variant, "danger")}" data-action="confirm">${escapeHtml(confirmText)}</button>
          </div>
        </div>
      </div>
    `;
      document.body.appendChild(modalEl);
      const triggerEl = document.createElement("button");
      triggerEl.type = "button";
      triggerEl.setAttribute("data-fs-target", `#${modalId}`);
      triggerEl.style.display = "none";
      document.body.appendChild(triggerEl);
      const modal = new Modal(triggerEl);
      let settled = false;
      const finish = (result) => {
        var _a;
        if (settled) return;
        settled = true;
        modal.dispose();
        modalEl.remove();
        triggerEl.remove();
        (_a = invokerEl.focus) == null ? void 0 : _a.call(invokerEl);
        resolve(result);
      };
      modalEl.addEventListener("click", (event) => {
        var _a;
        const action = (_a = event.target.closest("[data-action]")) == null ? void 0 : _a.dataset.action;
        if (action === "confirm") finish(true);
        else if (action === "cancel") finish(false);
      });
      triggerEl.addEventListener("fs:modal:hidden", () => finish(false));
      modal.show();
    });
  }

  // packages/fokus-js/js/tag.js
  var instances5 = createInstanceRegistry();
  var Tag = class {
    constructor(tagEl) {
      this.tagEl = tagEl;
      this._isLoading = tagEl.classList.contains("is-loading") || tagEl.getAttribute("aria-busy") === "true";
      this._handleClick = this._handleClick.bind(this);
      tagEl.addEventListener("click", this._handleClick);
      this.setLoading(this._isLoading);
      instances5.set(tagEl, this);
    }
    static getInstance(el) {
      return instances5.get(el);
    }
    _handleClick(event) {
      if (!this._isLoading && event.target.closest('[data-fs-dismiss="tag"]')) {
        this.dismiss();
      }
    }
    // Dispara um evento cancelável antes de remover (mesmo espírito de
    // fs:stepper:beforechange): preventDefault() bloqueia a remoção.
    dismiss() {
      if (this._isLoading) return false;
      const dismissButton = this.tagEl.querySelector('[data-fs-dismiss="tag"]');
      const shouldRestoreFocus = dismissButton && document.activeElement === dismissButton;
      const event = new CustomEvent("fs:tag:dismissed", { bubbles: true, cancelable: true });
      this.tagEl.dispatchEvent(event);
      if (event.defaultPrevented) return false;
      const focusTarget = shouldRestoreFocus ? this._getFocusTarget() : null;
      this.tagEl.remove();
      focusTarget == null ? void 0 : focusTarget.focus();
      return true;
    }
    _getFocusTarget() {
      const group = this.tagEl.closest(".fs-tag-group");
      if (!group) return null;
      const buttons = [...group.querySelectorAll('[data-fs-dismiss="tag"]')].filter((button) => !button.disabled && !button.hidden && button.getAttribute("aria-hidden") !== "true");
      const currentButton = this.tagEl.querySelector('[data-fs-dismiss="tag"]');
      const currentIndex = buttons.indexOf(currentButton);
      const remaining = buttons.filter((button) => button !== currentButton);
      return remaining[currentIndex] || remaining[currentIndex - 1] || null;
    }
    // Estado útil enquanto uma remoção ou atualização assíncrona está pendente.
    // Além da aparência, bloqueia o dismiss e comunica o estado a AT.
    setLoading(loading) {
      this._isLoading = Boolean(loading);
      this.tagEl.classList.toggle("is-loading", this._isLoading);
      if (this._isLoading) {
        this.tagEl.setAttribute("aria-busy", "true");
      } else {
        this.tagEl.removeAttribute("aria-busy");
      }
      const dismissButton = this.tagEl.querySelector('[data-fs-dismiss="tag"]');
      if (dismissButton) dismissButton.disabled = this._isLoading;
      return this;
    }
    dispose() {
      this.tagEl.removeEventListener("click", this._handleClick);
      instances5.delete(this.tagEl);
    }
  };
  autoInit("tag", Tag);

  // packages/fokus-js/js/file-drop.js
  var instances6 = createInstanceRegistry();
  var FileDrop = class {
    constructor(labelEl) {
      const inputEl = document.getElementById(labelEl.getAttribute("for"));
      if (!inputEl) {
        throw new Error("FokusStyles.FileDrop: input associado (via for=) n\xE3o encontrado.");
      }
      this.labelEl = labelEl;
      this.inputEl = inputEl;
      this._dragDepth = 0;
      this._handleDragEnter = this._handleDragEnter.bind(this);
      this._handleDragOver = this._handleDragOver.bind(this);
      this._handleDragLeave = this._handleDragLeave.bind(this);
      this._handleDrop = this._handleDrop.bind(this);
      labelEl.addEventListener("dragenter", this._handleDragEnter);
      labelEl.addEventListener("dragover", this._handleDragOver);
      labelEl.addEventListener("dragleave", this._handleDragLeave);
      labelEl.addEventListener("drop", this._handleDrop);
      instances6.set(labelEl, this);
    }
    static getInstance(el) {
      return instances6.get(el);
    }
    // Conta entradas/saídas (dragenter/dragleave também disparam nos filhos do
    // label, ex. o texto do hint) — só remove o estado visual quando a
    // contagem volta a zero, senão `.is-dragover` pisca ao arrastar por cima
    // de um elemento interno.
    _handleDragEnter(event) {
      event.preventDefault();
      this._dragDepth += 1;
      this.labelEl.classList.add("is-dragover");
    }
    _handleDragOver(event) {
      event.preventDefault();
    }
    _handleDragLeave(event) {
      event.preventDefault();
      this._dragDepth = Math.max(0, this._dragDepth - 1);
      if (this._dragDepth === 0) {
        this.labelEl.classList.remove("is-dragover");
      }
    }
    _handleDrop(event) {
      var _a;
      event.preventDefault();
      this._dragDepth = 0;
      this.labelEl.classList.remove("is-dragover");
      if (this.inputEl.disabled) return;
      const files = (_a = event.dataTransfer) == null ? void 0 : _a.files;
      if (!files || files.length === 0) return;
      this.inputEl.files = files;
      this.inputEl.dispatchEvent(new Event("change", { bubbles: true }));
    }
    dispose() {
      this.labelEl.removeEventListener("dragenter", this._handleDragEnter);
      this.labelEl.removeEventListener("dragover", this._handleDragOver);
      this.labelEl.removeEventListener("dragleave", this._handleDragLeave);
      this.labelEl.removeEventListener("drop", this._handleDrop);
      instances6.delete(this.labelEl);
    }
  };
  autoInit("file-drop", FileDrop);

  // packages/fokus-js/js/offcanvas.js
  var instances7 = createInstanceRegistry();
  var Offcanvas = class _Offcanvas {
    constructor(triggerEl, options = {}) {
      var _a, _b;
      const targetSelector = triggerEl.getAttribute("data-fs-target");
      const offcanvasEl = targetSelector ? document.querySelector(targetSelector) : null;
      if (!offcanvasEl) {
        throw new Error("FokusStyles.Offcanvas: elemento n\xE3o encontrado (data-fs-target).");
      }
      this.triggerEl = triggerEl;
      this.offcanvasEl = offcanvasEl;
      this.hasBackdrop = ((_a = options.backdrop) != null ? _a : offcanvasEl.getAttribute("data-backdrop")) !== "false";
      this.staticBackdrop = ((_b = options.backdrop) != null ? _b : offcanvasEl.getAttribute("data-backdrop")) === "static";
      this.isOpen = false;
      this._focusTrap = null;
      this._outsideClickCleanup = null;
      this._backdropEl = null;
      offcanvasEl.setAttribute("role", "dialog");
      offcanvasEl.setAttribute("aria-modal", "true");
      offcanvasEl.setAttribute("aria-hidden", "true");
      const titleEl = offcanvasEl.querySelector(".fs-offcanvas-title");
      if (titleEl) {
        if (!titleEl.id) titleEl.id = `fs-offcanvas-title-${Math.random().toString(36).slice(2)}`;
        offcanvasEl.setAttribute("aria-labelledby", titleEl.id);
      }
      this._handleTriggerClick = this._handleTriggerClick.bind(this);
      this._handleDismissClick = this._handleDismissClick.bind(this);
      triggerEl.addEventListener("click", this._handleTriggerClick);
      offcanvasEl.addEventListener("click", this._handleDismissClick);
      this._removeEscapeListener = onEscapeKey((event) => {
        if (this.isOpen && isTopOverlay(this, event) && !this.staticBackdrop) this.hide();
      });
      instances7.set(triggerEl, this);
    }
    static getInstance(el) {
      return instances7.get(el);
    }
    static getOrCreateInstance(el, options = {}) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _Offcanvas(el, options);
    }
    static show(el, options = {}) {
      return this.getOrCreateInstance(el, options).show();
    }
    static hide(el) {
      var _a;
      return (_a = this.getInstance(el)) == null ? void 0 : _a.hide();
    }
    static toggle(el, options = {}) {
      return this.getOrCreateInstance(el, options).toggle();
    }
    _handleTriggerClick(event) {
      event.preventDefault();
      this.show();
    }
    _handleDismissClick(event) {
      if (isTopOverlay(this, event) && event.target.closest('[data-fs-dismiss="offcanvas"]')) {
        this.hide();
      }
    }
    show() {
      if (this.isOpen) return;
      this.triggerEl.dispatchEvent(new CustomEvent("fs:show", { bubbles: true }));
      this.isOpen = true;
      registerOverlay(this, this.offcanvasEl);
      if (this.hasBackdrop) {
        this._backdropEl = document.createElement("div");
        this._backdropEl.className = "fs-offcanvas-backdrop";
        document.body.appendChild(this._backdropEl);
        void this._backdropEl.offsetHeight;
        this._backdropEl.classList.add("is-open");
      }
      this.offcanvasEl.classList.add("is-open");
      updateFocusIsolation(() => this.offcanvasEl.setAttribute("aria-hidden", "false"));
      lockScroll();
      this._focusTrap = createFocusTrap(this.offcanvasEl);
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          if (this.isOpen) this._focusTrap.activate();
        });
      });
      if (!this.staticBackdrop) {
        this._outsideClickCleanup = onClickOutside(this.offcanvasEl, (event) => {
          if (!isTopOverlay(this, event) || this.triggerEl.contains(event.target)) return;
          this.hide();
        });
      }
      this.triggerEl.dispatchEvent(new CustomEvent("fs:shown", { bubbles: true }));
      this.triggerEl.dispatchEvent(new CustomEvent("fs:offcanvas:shown", { bubbles: true }));
    }
    hide() {
      var _a, _b, _c;
      if (!this.isOpen) return;
      this.triggerEl.dispatchEvent(new CustomEvent("fs:hide", { bubbles: true }));
      this.isOpen = false;
      const wasTop = unregisterOverlay(this);
      (_a = this._focusTrap) == null ? void 0 : _a.deactivate();
      this._focusTrap = null;
      if (wasTop && this.triggerEl.isConnected && !this.triggerEl.closest("[inert]")) this.triggerEl.focus();
      this.offcanvasEl.classList.remove("is-open");
      updateFocusIsolation(() => this.offcanvasEl.setAttribute("aria-hidden", "true"));
      unlockScroll();
      (_b = this._backdropEl) == null ? void 0 : _b.remove();
      this._backdropEl = null;
      (_c = this._outsideClickCleanup) == null ? void 0 : _c.call(this);
      this._outsideClickCleanup = null;
      this.triggerEl.dispatchEvent(new CustomEvent("fs:hidden", { bubbles: true }));
      this.triggerEl.dispatchEvent(new CustomEvent("fs:offcanvas:hidden", { bubbles: true }));
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      this.hide();
      this._removeEscapeListener();
      this.triggerEl.removeEventListener("click", this._handleTriggerClick);
      this.offcanvasEl.removeEventListener("click", this._handleDismissClick);
      instances7.delete(this.triggerEl);
    }
  };
  autoInit("offcanvas", Offcanvas, { selector: '[data-fs="offcanvas"], [data-fs-toggle="offcanvas"]' });

  // packages/fokus-js/js/select.js
  var instances8 = createInstanceRegistry();
  var Select = class {
    constructor(selectEl) {
      var _a;
      if (selectEl.tagName !== "SELECT") {
        throw new Error("FokusStyles.Select: elemento precisa ser um <select> nativo.");
      }
      this.selectEl = selectEl;
      this.size = selectEl.getAttribute("data-size");
      this._originalDisplay = selectEl.style.display;
      this._buildMarkup();
      this._syncFromNativeSelect();
      this.dropdown = new Dropdown(this.toggleEl);
      this.toggleEl.setAttribute("aria-haspopup", "listbox");
      this.menuEl.setAttribute("role", "listbox");
      this.toggleEl.addEventListener("fs:dropdown:shown", () => {
        this.menuEl.style.width = `${this.toggleEl.offsetWidth}px`;
      });
      this._handleItemClick = this._handleItemClick.bind(this);
      this._handleNativeChange = this._handleNativeChange.bind(this);
      this.menuEl.addEventListener("click", this._handleItemClick);
      this.selectEl.addEventListener("change", this._handleNativeChange);
      this._form = selectEl.form;
      this._handleReset = () => {
        clearTimeout(this._resetTimer);
        this._resetTimer = setTimeout(() => {
          if (instances8.get(selectEl) === this) this._syncFromNativeSelect();
        });
      };
      (_a = this._form) == null ? void 0 : _a.addEventListener("reset", this._handleReset);
      instances8.set(selectEl, this);
    }
    static getInstance(el) {
      return instances8.get(el);
    }
    get isOpen() {
      var _a, _b;
      return (_b = (_a = this.dropdown) == null ? void 0 : _a.isOpen) != null ? _b : false;
    }
    _buildMarkup() {
      const wrapper = document.createElement("div");
      wrapper.className = "fs-dropdown fs-form-select-dropdown";
      const toggle = document.createElement("button");
      toggle.type = "button";
      toggle.className = "fs-form-select";
      if (this.size === "sm" || this.size === "lg") {
        toggle.classList.add(`fs-form-select-${this.size}`);
      }
      toggle.disabled = this.selectEl.disabled;
      const menu = document.createElement("div");
      menu.className = "fs-dropdown-menu";
      Array.from(this.selectEl.options).forEach((option) => {
        const item = document.createElement("button");
        item.type = "button";
        item.className = "fs-dropdown-item";
        item.setAttribute("role", "option");
        item.textContent = option.textContent;
        if (option.disabled) item.classList.add("is-disabled");
        menu.appendChild(item);
      });
      this.selectEl.insertAdjacentElement("afterend", wrapper);
      wrapper.appendChild(toggle);
      wrapper.appendChild(menu);
      this.selectEl.style.display = "none";
      this.wrapperEl = wrapper;
      this.toggleEl = toggle;
      this.menuEl = menu;
    }
    _syncFromNativeSelect() {
      const selectedOption = this.selectEl.options[this.selectEl.selectedIndex];
      this.toggleEl.textContent = selectedOption ? selectedOption.textContent : "";
      Array.from(this.menuEl.children).forEach((item, index) => {
        const isSelected = index === this.selectEl.selectedIndex;
        item.classList.toggle("is-active", isSelected);
        item.setAttribute("aria-selected", String(isSelected));
      });
    }
    _handleItemClick(event) {
      const item = event.target.closest(".fs-dropdown-item:not(.is-disabled)");
      if (!item) return;
      const index = Array.from(this.menuEl.children).indexOf(item);
      this.selectEl.selectedIndex = index;
      this._syncFromNativeSelect();
      this.selectEl.dispatchEvent(new Event("change", { bubbles: true }));
      this.selectEl.dispatchEvent(
        new CustomEvent("fs:select:changed", { bubbles: true, detail: { value: this.selectEl.value } })
      );
    }
    _handleNativeChange() {
      this._syncFromNativeSelect();
    }
    show() {
      this.dropdown.show();
    }
    hide() {
      this.dropdown.hide();
    }
    toggle() {
      this.dropdown.toggle();
    }
    dispose() {
      var _a;
      clearTimeout(this._resetTimer);
      (_a = this._form) == null ? void 0 : _a.removeEventListener("reset", this._handleReset);
      this.dropdown.dispose();
      this.menuEl.removeEventListener("click", this._handleItemClick);
      this.selectEl.removeEventListener("change", this._handleNativeChange);
      this.wrapperEl.remove();
      this.selectEl.style.display = this._originalDisplay;
      instances8.delete(this.selectEl);
    }
  };
  autoInit("select", Select);

  // packages/fokus-js/js/accordion.js
  var instances9 = createInstanceRegistry();
  var idCounter4 = 0;
  var Accordion = class {
    constructor(accordionEl) {
      this.accordionEl = accordionEl;
      this.exclusive = accordionEl.getAttribute("data-multiple") !== "true" && accordionEl.getAttribute("data-fs-always-open") !== "true";
      this.alwaysOpen = accordionEl.getAttribute("data-fs-always-open") === "true";
      this.items = [];
      const buttons = Array.from(accordionEl.querySelectorAll(".fs-accordion-button"));
      buttons.forEach((button) => {
        const panel = button.closest(".fs-accordion-item").querySelector(".fs-accordion-collapse");
        idCounter4 += 1;
        if (!panel.id) panel.id = `fokus-accordion-panel-${idCounter4}`;
        if (!button.id) button.id = `fokus-accordion-button-${idCounter4}`;
        button.setAttribute("aria-controls", panel.id);
        panel.setAttribute("role", "region");
        panel.setAttribute("aria-labelledby", button.id);
        const startsOpen = button.getAttribute("aria-expanded") === "true";
        button.setAttribute("aria-expanded", String(startsOpen));
        if (!startsOpen) {
          panel.style.display = "none";
        }
        panel.hidden = !startsOpen;
        const entry = { button, panel, handleClick: () => this._toggle(entry) };
        this.items.push(entry);
        button.addEventListener("click", entry.handleClick);
      });
      instances9.set(accordionEl, this);
    }
    static getInstance(el) {
      return instances9.get(el);
    }
    _toggle(entry) {
      const isOpen = entry.button.getAttribute("aria-expanded") === "true";
      if (isOpen) {
        if (this.alwaysOpen) return;
        this._close(entry);
        return;
      }
      if (this.exclusive) {
        this.items.filter((other) => other !== entry && other.button.getAttribute("aria-expanded") === "true").forEach((other) => this._close(other));
      }
      this._open(entry);
    }
    _open(entry) {
      entry.button.dispatchEvent(new CustomEvent("fs:show", { bubbles: true }));
      entry.button.setAttribute("aria-expanded", "true");
      entry.panel.hidden = false;
      expand(entry.panel).then((completed) => {
        if (!completed) return;
        entry.button.dispatchEvent(new CustomEvent("fs:shown", { bubbles: true }));
        entry.button.dispatchEvent(new CustomEvent("fs:accordion:shown", { bubbles: true }));
      });
    }
    _close(entry) {
      entry.button.dispatchEvent(new CustomEvent("fs:hide", { bubbles: true }));
      entry.button.setAttribute("aria-expanded", "false");
      collapse(entry.panel).then((completed) => {
        if (!completed) return;
        entry.panel.hidden = true;
        entry.button.dispatchEvent(new CustomEvent("fs:hidden", { bubbles: true }));
        entry.button.dispatchEvent(new CustomEvent("fs:accordion:hidden", { bubbles: true }));
      });
    }
    dispose() {
      this.items.forEach((entry) => {
        cancelHeightTransition(entry.panel);
        entry.button.removeEventListener("click", entry.handleClick);
      });
      instances9.delete(this.accordionEl);
    }
  };
  autoInit("accordion", Accordion);

  // packages/fokus-js/js/collapse.js
  var instances10 = createInstanceRegistry();
  var idCounter5 = 0;
  var Collapse = class {
    constructor(triggerEl) {
      const targetSelector = triggerEl.getAttribute("data-fs-target");
      const collapseEl = targetSelector ? document.querySelector(targetSelector) : null;
      if (!collapseEl) {
        throw new Error("FokusStyles.Collapse: elemento n\xE3o encontrado (data-fs-target).");
      }
      this.triggerEl = triggerEl;
      this.collapseEl = collapseEl;
      this.isOpen = triggerEl.getAttribute("aria-expanded") === "true";
      idCounter5 += 1;
      if (!collapseEl.id) collapseEl.id = `fokus-collapse-${idCounter5}`;
      triggerEl.setAttribute("aria-controls", collapseEl.id);
      triggerEl.setAttribute("aria-expanded", String(this.isOpen));
      if (!this.isOpen) {
        collapseEl.style.display = "none";
      }
      this._handleClick = () => this.toggle();
      triggerEl.addEventListener("click", this._handleClick);
      instances10.set(triggerEl, this);
    }
    static getInstance(el) {
      return instances10.get(el);
    }
    show() {
      if (this.isOpen) return;
      this.isOpen = true;
      this.triggerEl.setAttribute("aria-expanded", "true");
      expand(this.collapseEl).then((completed) => {
        if (!completed) return;
        this.triggerEl.dispatchEvent(new CustomEvent("fs:collapse:shown", { bubbles: true }));
      });
    }
    hide() {
      if (!this.isOpen) return;
      this.isOpen = false;
      this.triggerEl.setAttribute("aria-expanded", "false");
      collapse(this.collapseEl).then((completed) => {
        if (!completed) return;
        this.triggerEl.dispatchEvent(new CustomEvent("fs:collapse:hidden", { bubbles: true }));
      });
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      cancelHeightTransition(this.collapseEl);
      this.triggerEl.removeEventListener("click", this._handleClick);
      instances10.delete(this.triggerEl);
    }
  };
  autoInit("collapse", Collapse);

  // packages/fokus-js/js/tabs.js
  var instances11 = createInstanceRegistry();
  var idCounter6 = 0;
  var Tabs = class _Tabs {
    constructor(tablistEl) {
      this.tablistEl = tablistEl;
      this.tabs = Array.from(tablistEl.querySelectorAll(".fs-nav-link, [role=tab], [data-fs-tab-target]"));
      this.manualActivation = tablistEl.getAttribute("data-tabs-activation") === "manual";
      tablistEl.setAttribute("role", "tablist");
      this.tabs.forEach((tab) => {
        const isActive = tab.classList.contains("is-active");
        idCounter6 += 1;
        if (!tab.id) tab.id = `fokus-tab-${idCounter6}`;
        tab.setAttribute("role", "tab");
        tab.setAttribute("aria-selected", String(isActive));
        tab.setAttribute("tabindex", isActive ? "0" : "-1");
        const paneSelector = tab.getAttribute("data-fs-target") || tab.getAttribute("data-fs-tab-target");
        const pane = paneSelector ? document.querySelector(paneSelector) : null;
        if (pane) {
          pane.setAttribute("role", "tabpanel");
          pane.setAttribute("aria-labelledby", tab.id);
          pane.setAttribute("tabindex", "0");
          pane.hidden = !isActive;
        }
      });
      this._handleClick = this._handleClick.bind(this);
      this._handleKeydown = this._handleKeydown.bind(this);
      tablistEl.addEventListener("click", this._handleClick);
      tablistEl.addEventListener("keydown", this._handleKeydown);
      instances11.set(tablistEl, this);
    }
    static getInstance(el) {
      return instances11.get(el);
    }
    static getOrCreateInstance(el) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _Tabs(el);
    }
    static show(el, tab) {
      return this.getOrCreateInstance(el).show(tab);
    }
    static dispose(el) {
      var _a;
      return (_a = this.getInstance(el)) == null ? void 0 : _a.dispose();
    }
    _handleClick(event) {
      const tab = event.target.closest(".fs-nav-link:not(.is-disabled), [role=tab]:not(.is-disabled), [data-fs-tab-target]:not(.is-disabled)");
      if (!tab || !this.tabs.includes(tab)) return;
      this.show(tab);
    }
    _handleKeydown(event) {
      const enabledTabs = this.tabs.filter((tab) => !tab.classList.contains("is-disabled"));
      const currentIndex = enabledTabs.indexOf(document.activeElement);
      if (currentIndex === -1) return;
      const vertical = this.tablistEl.getAttribute("aria-orientation") === "vertical" || this.tablistEl.classList.contains("fs-tabs-vertical");
      let nextIndex = null;
      if (event.key === (vertical ? "ArrowDown" : "ArrowRight")) nextIndex = (currentIndex + 1) % enabledTabs.length;
      else if (event.key === (vertical ? "ArrowUp" : "ArrowLeft")) nextIndex = (currentIndex - 1 + enabledTabs.length) % enabledTabs.length;
      else if (event.key === "Home") nextIndex = 0;
      else if (event.key === "End") nextIndex = enabledTabs.length - 1;
      if (nextIndex === null) return;
      event.preventDefault();
      const nextTab = enabledTabs[nextIndex];
      nextTab.focus();
      if (!this.manualActivation) this.show(nextTab);
    }
    show(tab) {
      if (tab.classList.contains("is-active")) return;
      this.tabs.forEach((otherTab) => {
        const isActive = otherTab === tab;
        otherTab.classList.toggle("is-active", isActive);
        otherTab.setAttribute("aria-selected", String(isActive));
        otherTab.setAttribute("tabindex", isActive ? "0" : "-1");
        const paneSelector = otherTab.getAttribute("data-fs-target") || otherTab.getAttribute("data-fs-tab-target");
        const pane = paneSelector ? document.querySelector(paneSelector) : null;
        pane == null ? void 0 : pane.classList.toggle("is-active", isActive);
        if (pane) pane.hidden = !isActive;
      });
      tab.dispatchEvent(new CustomEvent("fs:shown", { bubbles: true }));
      tab.dispatchEvent(
        new CustomEvent("fs:tab:changed", { bubbles: true, detail: { target: tab.getAttribute("data-fs-target") } })
      );
    }
    dispose() {
      this.tablistEl.removeEventListener("click", this._handleClick);
      this.tablistEl.removeEventListener("keydown", this._handleKeydown);
      instances11.delete(this.tablistEl);
    }
  };
  autoInit("tabs", Tabs);

  // packages/fokus-js/js/toast.js
  var instances12 = createInstanceRegistry();
  var Toast = class {
    constructor(toastEl) {
      this.toastEl = toastEl;
      this.delay = Number(toastEl.getAttribute("data-delay")) || 4e3;
      this.autohide = toastEl.getAttribute("data-autohide") !== "false";
      this.isOpen = false;
      this._hideTimer = null;
      this._paused = false;
      this._remaining = this.delay;
      this._startedAt = 0;
      this._progressTimer = null;
      toastEl.setAttribute("role", "status");
      toastEl.setAttribute("aria-live", "polite");
      toastEl.style.display = "none";
      this._handleDismissClick = this._handleDismissClick.bind(this);
      this._pause = () => {
        if (!this.isOpen || this._paused) return;
        this._paused = true;
        clearTimeout(this._hideTimer);
        if (this.autohide && this._startedAt) {
          this._remaining = Math.max(0, this._remaining - (Date.now() - this._startedAt));
        }
        this._updateProgress();
        clearInterval(this._progressTimer);
        this.toastEl.classList.add("is-paused");
      };
      this._resume = () => {
        if (!this.isOpen || !this._paused) return;
        this._paused = false;
        this.toastEl.classList.remove("is-paused");
        this._startProgress();
        if (this.autohide) this._scheduleHide();
      };
      toastEl.addEventListener("click", this._handleDismissClick);
      toastEl.addEventListener("mouseenter", this._pause);
      toastEl.addEventListener("mouseleave", this._resume);
      toastEl.addEventListener("focusin", this._pause);
      toastEl.addEventListener("focusout", this._resume);
      instances12.set(toastEl, this);
    }
    static getInstance(el) {
      return instances12.get(el);
    }
    _handleDismissClick(event) {
      if (event.target.closest('[data-fs-dismiss="toast"]')) {
        this.hide();
      }
    }
    show() {
      if (this.isOpen) return;
      this.toastEl.dispatchEvent(new CustomEvent("fs:show", { bubbles: true }));
      this.isOpen = true;
      this._remaining = this.delay;
      this._startedAt = Date.now();
      this.toastEl.style.setProperty("--fs-toast-duration", `${this.delay}ms`);
      const expansion = expand(this.toastEl);
      this.toastEl.classList.remove("is-progressing");
      void this.toastEl.offsetWidth;
      if (this.toastEl.getAttribute("data-toast-progress") === "true" && this.autohide) {
        this.toastEl.classList.add("is-progressing");
        this._startProgress();
      }
      expansion.then((completed) => {
        if (!completed) return;
        this.toastEl.dispatchEvent(new CustomEvent("fs:shown", { bubbles: true }));
        this.toastEl.dispatchEvent(new CustomEvent("fs:toast:shown", { bubbles: true }));
      });
      this._scheduleHide();
    }
    _scheduleHide() {
      clearTimeout(this._hideTimer);
      if (this.autohide && !this._paused) {
        this._startedAt = Date.now();
        this._hideTimer = setTimeout(() => this.hide(), this._remaining);
      }
    }
    _updateProgress() {
      if (this.toastEl.getAttribute("data-toast-progress") !== "true" || !this.autohide) return;
      const percentage = Math.max(0, Math.min(100, this._remaining / this.delay * 100));
      const progressEl = this.toastEl.querySelector(".fs-toast-progress");
      if (progressEl) progressEl.style.inlineSize = `${percentage}%`;
    }
    _startProgress() {
      if (this.toastEl.getAttribute("data-toast-progress") !== "true" || !this.autohide) return;
      clearInterval(this._progressTimer);
      this._updateProgress();
      this._progressTimer = setInterval(() => {
        if (!this.isOpen || this._paused) return;
        this._remaining = Math.max(0, this.delay - (Date.now() - this._startedAt));
        this._updateProgress();
      }, 50);
    }
    hide() {
      if (!this.isOpen) return;
      this.toastEl.dispatchEvent(new CustomEvent("fs:hide", { bubbles: true }));
      this.isOpen = false;
      clearTimeout(this._hideTimer);
      this._hideTimer = null;
      clearInterval(this._progressTimer);
      this._progressTimer = null;
      this._startedAt = 0;
      this._paused = false;
      this._remaining = this.delay;
      this.toastEl.classList.remove("is-paused");
      this.toastEl.classList.remove("is-progressing");
      const progressEl = this.toastEl.querySelector(".fs-toast-progress");
      if (progressEl) progressEl.style.removeProperty("inline-size");
      collapse(this.toastEl).then((completed) => {
        if (!completed) return;
        this.toastEl.dispatchEvent(new CustomEvent("fs:hidden", { bubbles: true }));
        this.toastEl.dispatchEvent(new CustomEvent("fs:toast:hidden", { bubbles: true }));
      });
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      cancelHeightTransition(this.toastEl);
      clearTimeout(this._hideTimer);
      clearInterval(this._progressTimer);
      this.toastEl.removeEventListener("click", this._handleDismissClick);
      this.toastEl.removeEventListener("mouseenter", this._pause);
      this.toastEl.removeEventListener("mouseleave", this._resume);
      this.toastEl.removeEventListener("focusin", this._pause);
      this.toastEl.removeEventListener("focusout", this._resume);
      instances12.delete(this.toastEl);
    }
  };
  autoInit("toast", Toast);

  // packages/fokus-js/js/carousel.js
  var instances13 = createInstanceRegistry();
  var interactiveSelector = "a, button, input, select, textarea, label, [contenteditable='true']";
  var Carousel = class _Carousel {
    constructor(carouselEl) {
      var _a, _b, _c;
      this.carouselEl = carouselEl;
      this.inner = carouselEl.querySelector(".fs-carousel-inner");
      this.items = Array.from(carouselEl.querySelectorAll(".fs-carousel-item"));
      this.indicators = Array.from(carouselEl.querySelectorAll(".fs-carousel-indicators button"));
      this.prevBtn = carouselEl.querySelector(".fs-carousel-control-prev");
      this.nextBtn = carouselEl.querySelector(".fs-carousel-control-next");
      this.toggleBtn = carouselEl.querySelector("[data-fs-carousel-toggle]");
      this.counterEl = carouselEl.querySelector("[data-fs-carousel-counter]");
      this.progressEl = carouselEl.querySelector("[data-fs-carousel-progress]");
      this.interval = Number(carouselEl.getAttribute("data-interval")) || 5e3;
      this.autoplay = carouselEl.getAttribute("data-autoplay") === "true";
      this.dragEnabled = carouselEl.getAttribute("data-drag") !== "false";
      this.timer = null;
      this.userPaused = false;
      this.isHovering = false;
      this.isFocusWithin = false;
      this.drag = null;
      this.isStatic = this.items.length < 2;
      this.index = Math.max(this.items.findIndex((item) => item.classList.contains("is-active")), 0);
      carouselEl.setAttribute("role", "group");
      carouselEl.setAttribute("aria-roledescription", "carousel");
      carouselEl.classList.toggle("is-static", this.isStatic);
      carouselEl.classList.toggle("is-two-slides", this.items.length === 2);
      if (!this.isStatic && !carouselEl.hasAttribute("tabindex")) {
        carouselEl.setAttribute("tabindex", "0");
        this.managesTabindex = true;
      }
      this._render();
      this._handlePrev = () => this.prev();
      this._handleNext = () => this.next();
      this._handleToggle = () => this.userPaused ? this.play() : this.pause();
      this._handleIndicator = (event) => {
        const index = this.indicators.indexOf(event.currentTarget);
        if (index !== -1) this.goTo(index);
      };
      this._handleKeydown = this._handleKeydown.bind(this);
      this._handlePointerDown = this._handlePointerDown.bind(this);
      this._handlePointerMove = this._handlePointerMove.bind(this);
      this._handlePointerEnd = this._handlePointerEnd.bind(this);
      this._handleVisibilityChange = this._handleVisibilityChange.bind(this);
      this._handleMouseEnter = () => {
        this.isHovering = true;
        this._syncAutoplay();
      };
      this._handleMouseLeave = () => {
        this.isHovering = false;
        this._syncAutoplay();
      };
      this._handleFocusIn = () => {
        this.isFocusWithin = true;
        this._syncAutoplay();
      };
      this._handleFocusOut = () => {
        queueMicrotask(() => {
          this.isFocusWithin = carouselEl.contains(document.activeElement);
          this._syncAutoplay();
        });
      };
      (_a = this.prevBtn) == null ? void 0 : _a.addEventListener("click", this._handlePrev);
      (_b = this.nextBtn) == null ? void 0 : _b.addEventListener("click", this._handleNext);
      (_c = this.toggleBtn) == null ? void 0 : _c.addEventListener("click", this._handleToggle);
      this.indicators.forEach((btn) => btn.addEventListener("click", this._handleIndicator));
      carouselEl.addEventListener("keydown", this._handleKeydown);
      carouselEl.addEventListener("pointerdown", this._handlePointerDown);
      carouselEl.addEventListener("pointermove", this._handlePointerMove);
      carouselEl.addEventListener("pointerup", this._handlePointerEnd);
      carouselEl.addEventListener("pointercancel", this._handlePointerEnd);
      carouselEl.addEventListener("mouseenter", this._handleMouseEnter);
      carouselEl.addEventListener("mouseleave", this._handleMouseLeave);
      carouselEl.addEventListener("focusin", this._handleFocusIn);
      carouselEl.addEventListener("focusout", this._handleFocusOut);
      document.addEventListener("visibilitychange", this._handleVisibilityChange);
      instances13.set(carouselEl, this);
      this._syncAutoplay();
    }
    static getInstance(el) {
      return instances13.get(el);
    }
    static getOrCreateInstance(el) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _Carousel(el);
    }
    static next(el) {
      return this.getOrCreateInstance(el).next();
    }
    static prev(el) {
      return this.getOrCreateInstance(el).prev();
    }
    static goTo(el, index) {
      return this.getOrCreateInstance(el).goTo(index);
    }
    _isDocumentVisible() {
      return document.visibilityState !== "hidden";
    }
    _isTemporarilyPaused() {
      return this.isHovering || this.isFocusWithin || !this._isDocumentVisible() || Boolean(this.drag);
    }
    _isAutoplayEnabled() {
      return this.autoplay && !this.isStatic && !this.userPaused && !this._isTemporarilyPaused();
    }
    _syncAutoplay() {
      if (this.timer) {
        clearInterval(this.timer);
        this.timer = null;
      }
      if (this._isAutoplayEnabled()) this.timer = setInterval(() => this.next(), this.interval);
      this._syncAutoplayA11y();
    }
    _syncAutoplayA11y() {
      if (this.inner) this.inner.setAttribute("aria-live", this.timer ? "off" : "polite");
      this.carouselEl.classList.toggle(
        "is-autoplay-paused",
        this.autoplay && !this.isStatic && !this.timer
      );
      if (this.progressEl) {
        this.progressEl.style.setProperty("--fs-carousel-progress-duration", `${this.interval}ms`);
        this.progressEl.classList.remove("is-running");
        if (this.timer) {
          void this.progressEl.offsetWidth;
          this.progressEl.classList.add("is-running");
        }
      }
      if (!this.toggleBtn) return;
      const isPlaying = this.autoplay && !this.userPaused && !this.isStatic;
      this.toggleBtn.disabled = !this.autoplay || this.isStatic;
      this.toggleBtn.setAttribute("aria-pressed", String(isPlaying));
      this.toggleBtn.setAttribute(
        "aria-label",
        isPlaying ? "Pausar reprodu\xE7\xE3o autom\xE1tica" : "Reproduzir reprodu\xE7\xE3o autom\xE1tica"
      );
    }
    _render() {
      if (this.inner && !this.carouselEl.classList.contains("fs-carousel-fade")) {
        this.inner.style.transform = `translate3d(-${this.index * 100}%, 0, 0)`;
      }
      this.items.forEach((item, index) => {
        item.classList.toggle("is-active", index === this.index);
        item.setAttribute("aria-hidden", String(index !== this.index));
        item.toggleAttribute("inert", index !== this.index);
      });
      this.indicators.forEach((btn, index) => {
        btn.classList.toggle("is-active", index === this.index);
        btn.setAttribute("aria-current", String(index === this.index));
      });
      if (this.counterEl) this.counterEl.textContent = `${this.index + 1} de ${this.items.length}`;
      this._syncAutoplayA11y();
    }
    _goToIndex(nextIndex) {
      const count = this.items.length;
      if (count < 2) return;
      const from = this.index;
      const to = (nextIndex % count + count) % count;
      if (to === from) return;
      this.index = to;
      this._render();
      this.carouselEl.dispatchEvent(
        new CustomEvent("fs:shown", { bubbles: true, detail: { from, to } })
      );
      this.carouselEl.dispatchEvent(
        new CustomEvent("fs:carousel:slid", { bubbles: true, detail: { from, to } })
      );
    }
    next() {
      this._goToIndex(this.index + 1);
    }
    prev() {
      this._goToIndex(this.index - 1);
    }
    goTo(index) {
      this._goToIndex(index);
    }
    pause() {
      if (!this.autoplay) return;
      this.userPaused = true;
      this._syncAutoplay();
    }
    play() {
      if (!this.autoplay || this.isStatic) return;
      this.userPaused = false;
      this._syncAutoplay();
    }
    _handleKeydown(event) {
      if (event.target !== this.carouselEl) return;
      if (event.key === "ArrowRight") {
        event.preventDefault();
        this.next();
      } else if (event.key === "ArrowLeft") {
        event.preventDefault();
        this.prev();
      } else if (event.key === "Home") {
        event.preventDefault();
        this.goTo(0);
      } else if (event.key === "End") {
        event.preventDefault();
        this.goTo(this.items.length - 1);
      }
    }
    _handlePointerDown(event) {
      var _a, _b, _c, _d;
      if (!this.dragEnabled || this.isStatic || this.carouselEl.classList.contains("fs-carousel-fade")) return;
      if (event.isPrimary === false || event.pointerType === "mouse" && event.button !== 0) return;
      if ((_b = (_a = event.target).closest) == null ? void 0 : _b.call(_a, interactiveSelector)) return;
      this.drag = { id: event.pointerId, startX: event.clientX, startY: event.clientY, deltaX: 0, horizontal: false };
      try {
        (_d = (_c = this.carouselEl).setPointerCapture) == null ? void 0 : _d.call(_c, event.pointerId);
      } catch (e) {
      }
      this._syncAutoplay();
    }
    _handlePointerMove(event) {
      if (!this.drag || event.pointerId !== this.drag.id) return;
      const deltaX = event.clientX - this.drag.startX;
      const deltaY = event.clientY - this.drag.startY;
      if (!this.drag.horizontal) {
        if (Math.abs(deltaY) > 8 && Math.abs(deltaY) >= Math.abs(deltaX)) {
          this._clearDrag();
          return;
        }
        if (Math.abs(deltaX) <= 8 || Math.abs(deltaX) < Math.abs(deltaY)) return;
        this.drag.horizontal = true;
        this.carouselEl.classList.add("is-dragging");
      }
      this.drag.deltaX = deltaX;
      this.inner.style.transform = `translate3d(calc(-${this.index * 100}% + ${deltaX}px), 0, 0)`;
      event.preventDefault();
    }
    _handlePointerEnd(event) {
      if (!this.drag || event.pointerId !== this.drag.id) return;
      const { deltaX, horizontal } = this.drag;
      this._clearDrag();
      this._render();
      if (event.type === "pointercancel" || !horizontal) return;
      const width = this.carouselEl.getBoundingClientRect().width;
      const threshold = Math.min(80, Math.max(40, width * 0.12));
      if (Math.abs(deltaX) < threshold) return;
      if (deltaX < 0) this.next();
      else this.prev();
    }
    _clearDrag() {
      var _a, _b;
      if (!this.drag) return;
      try {
        if ((_b = (_a = this.carouselEl).hasPointerCapture) == null ? void 0 : _b.call(_a, this.drag.id)) this.carouselEl.releasePointerCapture(this.drag.id);
      } catch (e) {
      }
      this.drag = null;
      this.carouselEl.classList.remove("is-dragging");
      this._syncAutoplay();
    }
    _handleVisibilityChange() {
      this._syncAutoplay();
    }
    dispose() {
      var _a, _b, _c;
      this.userPaused = true;
      this._clearDrag();
      this._syncAutoplay();
      (_a = this.prevBtn) == null ? void 0 : _a.removeEventListener("click", this._handlePrev);
      (_b = this.nextBtn) == null ? void 0 : _b.removeEventListener("click", this._handleNext);
      (_c = this.toggleBtn) == null ? void 0 : _c.removeEventListener("click", this._handleToggle);
      this.indicators.forEach((btn) => btn.removeEventListener("click", this._handleIndicator));
      this.carouselEl.removeEventListener("keydown", this._handleKeydown);
      this.carouselEl.removeEventListener("pointerdown", this._handlePointerDown);
      this.carouselEl.removeEventListener("pointermove", this._handlePointerMove);
      this.carouselEl.removeEventListener("pointerup", this._handlePointerEnd);
      this.carouselEl.removeEventListener("pointercancel", this._handlePointerEnd);
      this.carouselEl.removeEventListener("mouseenter", this._handleMouseEnter);
      this.carouselEl.removeEventListener("mouseleave", this._handleMouseLeave);
      this.carouselEl.removeEventListener("focusin", this._handleFocusIn);
      this.carouselEl.removeEventListener("focusout", this._handleFocusOut);
      document.removeEventListener("visibilitychange", this._handleVisibilityChange);
      if (this.managesTabindex) this.carouselEl.removeAttribute("tabindex");
      instances13.delete(this.carouselEl);
    }
  };
  autoInit("carousel", Carousel);

  // packages/fokus-js/js/stepper.js
  var instances14 = createInstanceRegistry();
  var Stepper = class {
    constructor(stepperEl) {
      var _a, _b;
      this.stepperEl = stepperEl;
      this.steps = Array.from(stepperEl.querySelectorAll(".fs-step"));
      this.linear = stepperEl.getAttribute("data-linear") !== "false";
      const contentPanels = Array.from(stepperEl.querySelectorAll(".fs-step-panel"));
      this.panels = this.steps.map(
        (step, i) => step.dataset.fsTarget ? stepperEl.querySelector(step.dataset.fsTarget) : contentPanels[i]
      );
      this.prevBtn = stepperEl.querySelector('[data-stepper="prev"]');
      this.nextBtn = stepperEl.querySelector('[data-stepper="next"]');
      this.finished = false;
      this.current = Math.max(
        this.steps.findIndex((step) => step.classList.contains("fs-step-active")),
        0
      );
      this.steps.forEach((step, i) => {
        step.setAttribute("role", "listitem");
        step.handleClick = () => this._onStepClick(i);
        step.addEventListener("click", step.handleClick);
        step.handleKeydown = (event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            this._onStepClick(i);
          }
        };
        step.addEventListener("keydown", step.handleKeydown);
      });
      this._handlePrev = () => this.prev();
      this._handleNext = () => this.next();
      (_a = this.prevBtn) == null ? void 0 : _a.addEventListener("click", this._handlePrev);
      (_b = this.nextBtn) == null ? void 0 : _b.addEventListener("click", this._handleNext);
      this._render();
      instances14.set(stepperEl, this);
    }
    static getInstance(el) {
      return instances14.get(el);
    }
    _onStepClick(index) {
      if (this.linear && index > this.current) return;
      this.goTo(index);
    }
    _render() {
      this.steps.forEach((step, i) => {
        const isActive = i === this.current && !this.finished;
        const isCompleted = i < this.current || this.finished;
        step.classList.toggle("fs-step-active", isActive);
        step.classList.toggle("fs-step-completed", isCompleted);
        step.setAttribute("aria-current", isActive ? "step" : "false");
        const clickable = this.linear ? isCompleted : true;
        step.classList.toggle("fs-step-clickable", clickable);
        step.setAttribute("tabindex", clickable ? "0" : "-1");
        const panel = this.panels[i];
        if (panel) panel.classList.toggle("is-active", i === this.current);
      });
      if (this.prevBtn) this.prevBtn.disabled = this.current === 0 && !this.finished;
    }
    _change(to) {
      const from = this.current;
      const target = Math.max(0, Math.min(to, this.steps.length - 1));
      if (target === from && !this.finished) return false;
      const before = new CustomEvent("fs:stepper:beforechange", {
        bubbles: true,
        cancelable: true,
        detail: { from, to: target }
      });
      this.stepperEl.dispatchEvent(before);
      if (before.defaultPrevented) return false;
      this.finished = false;
      this.current = target;
      this._render();
      this.stepperEl.dispatchEvent(
        new CustomEvent("fs:stepper:changed", { bubbles: true, detail: { from, to: target } })
      );
      return true;
    }
    next() {
      if (this.current < this.steps.length - 1) return this._change(this.current + 1);
      return this.complete();
    }
    prev() {
      return this._change(this.current - 1);
    }
    goTo(index) {
      return this._change(index);
    }
    setError(index, hasError = true) {
      const step = this.steps[index];
      if (step) step.classList.toggle("fs-step-error", hasError);
    }
    complete() {
      const before = new CustomEvent("fs:stepper:beforechange", {
        bubbles: true,
        cancelable: true,
        detail: { from: this.current, to: this.current, completing: true }
      });
      this.stepperEl.dispatchEvent(before);
      if (before.defaultPrevented) return false;
      this.finished = true;
      this._render();
      this.stepperEl.dispatchEvent(new CustomEvent("fs:stepper:completed", { bubbles: true }));
      return true;
    }
    dispose() {
      var _a, _b;
      this.steps.forEach((step) => {
        step.removeEventListener("click", step.handleClick);
        step.removeEventListener("keydown", step.handleKeydown);
      });
      (_a = this.prevBtn) == null ? void 0 : _a.removeEventListener("click", this._handlePrev);
      (_b = this.nextBtn) == null ? void 0 : _b.removeEventListener("click", this._handleNext);
      instances14.delete(this.stepperEl);
    }
  };
  autoInit("stepper", Stepper);

  // packages/fokus-js/js/breadcrumb.js
  var instances15 = createInstanceRegistry();
  var Breadcrumb = class {
    constructor(listEl, options = {}) {
      var _a, _b;
      this.listEl = listEl;
      this.maxItems = Number((_b = (_a = options.maxItems) != null ? _a : listEl.getAttribute("data-max-items")) != null ? _b : 4);
      this.items = Array.from(listEl.children).filter((el) => el.classList.contains("fs-breadcrumb-item"));
      this.isCollapsed = false;
      this._moreItem = null;
      this._dropdown = null;
      this._collapseQuery = window.matchMedia("(max-width: 639.98px)");
      this._setupTruncation();
      this._handleMediaChange = () => this._render();
      this._collapseQuery.addEventListener("change", this._handleMediaChange);
      this._render();
      instances15.set(listEl, this);
    }
    static getInstance(el) {
      return instances15.get(el);
    }
    // Trunca com reticências (CSS) e só anexa tooltip (js/tooltip.js) nos itens
    // cujo texto realmente transborda — evita tooltip redundante em labels
    // curtos que já cabem inteiros. Mede depois de `document.fonts.ready`: como
    // a tipografia é self-hosted (docs/definitions.md, seção 18.2), medir antes
    // da fonte carregar poderia usar a métrica do fallback do sistema e errar o
    // corte.
    _setupTruncation() {
      var _a;
      const targets = this.items.map((item) => {
        var _a2;
        return (_a2 = item.querySelector("a")) != null ? _a2 : item;
      });
      targets.forEach((target) => target.classList.add("fs-breadcrumb-item-truncate"));
      const measure = () => {
        targets.forEach((target) => {
          if (target.scrollWidth > target.clientWidth) {
            target.setAttribute("data-title", target.textContent.trim());
            new Tooltip(target);
          }
        });
      };
      if ((_a = document.fonts) == null ? void 0 : _a.ready) {
        document.fonts.ready.then(measure);
      } else {
        requestAnimationFrame(measure);
      }
    }
    _render() {
      const shouldCollapse = this._collapseQuery.matches && this.items.length > this.maxItems;
      if (shouldCollapse === this.isCollapsed) return;
      this.isCollapsed = shouldCollapse;
      if (shouldCollapse) {
        this._collapse();
      } else {
        this._expand();
      }
    }
    // Mantém o primeiro e o último nível visíveis; os intermediários viram um
    // único item "…" com dropdown (mesma composição de js/select.js: monta o
    // toggle + `.fs-dropdown-menu` como irmãos e deixa o Dropdown reposicionar).
    _collapse() {
      const hiddenItems = this.items.slice(1, -1);
      hiddenItems.forEach((item) => {
        item.style.display = "none";
      });
      const moreItem = document.createElement("li");
      moreItem.className = "fs-breadcrumb-item";
      const toggle = document.createElement("button");
      toggle.type = "button";
      toggle.className = "fs-breadcrumb-more";
      toggle.setAttribute("aria-label", "Mostrar n\xEDveis ocultos");
      toggle.textContent = "\u2026";
      const menu = document.createElement("div");
      menu.className = "fs-dropdown-menu";
      hiddenItems.forEach((item) => {
        var _a;
        const source = (_a = item.querySelector("a")) != null ? _a : item;
        const isLink = source.tagName === "A";
        const link = document.createElement(isLink ? "a" : "span");
        link.className = "fs-dropdown-item";
        link.textContent = source.textContent.trim();
        if (isLink) link.href = source.getAttribute("href");
        menu.appendChild(link);
      });
      moreItem.appendChild(toggle);
      moreItem.appendChild(menu);
      this.items[0].insertAdjacentElement("afterend", moreItem);
      this._moreItem = moreItem;
      this._dropdown = new Dropdown(toggle);
    }
    _expand() {
      var _a, _b;
      this.items.slice(1, -1).forEach((item) => {
        item.style.removeProperty("display");
      });
      (_a = this._dropdown) == null ? void 0 : _a.dispose();
      this._dropdown = null;
      (_b = this._moreItem) == null ? void 0 : _b.remove();
      this._moreItem = null;
    }
    dispose() {
      this._collapseQuery.removeEventListener("change", this._handleMediaChange);
      this._expand();
      instances15.delete(this.listEl);
    }
  };
  autoInit("breadcrumb", Breadcrumb);

  // packages/fokus-js/js/notification-center.js
  var instances16 = createInstanceRegistry();
  var idCounter7 = 0;
  function formatTime(time) {
    const date = new Date(time);
    const hh = String(date.getHours()).padStart(2, "0");
    const mm = String(date.getMinutes()).padStart(2, "0");
    return `${hh}:${mm}`;
  }
  var NotificationCenter = class {
    constructor(triggerEl, options = {}) {
      var _a, _b, _c, _d, _e, _f;
      const panelSelector = (_a = options.target) != null ? _a : triggerEl.getAttribute("data-fs-target");
      const panelEl = panelSelector ? document.querySelector(panelSelector) : null;
      if (!panelEl) {
        throw new Error("FokusStyles.NotificationCenter: painel n\xE3o encontrado (data-fs-target).");
      }
      this.triggerEl = triggerEl;
      this.panelEl = panelEl;
      this.storage = (_c = (_b = options.storage) != null ? _b : triggerEl.getAttribute("data-storage")) != null ? _c : "memory";
      this.storageKey = (_e = (_d = options.storageKey) != null ? _d : triggerEl.getAttribute("data-storage-key")) != null ? _e : "fokus-notifications";
      const containerSelector = (_f = options.toastContainer) != null ? _f : triggerEl.getAttribute("data-toast-container");
      this.toastContainer = containerSelector ? document.querySelector(containerSelector) : this._ensureToastContainer();
      this.isOpen = false;
      this._outsideClickCleanup = null;
      this.history = this._load();
      this.badgeEl = triggerEl.querySelector(".fs-notification-badge");
      this.listEl = panelEl.querySelector(".fs-notification-list");
      this.emptyEl = panelEl.querySelector(".fs-notification-empty");
      document.body.appendChild(this.panelEl);
      triggerEl.setAttribute("aria-haspopup", "dialog");
      triggerEl.setAttribute("aria-expanded", "false");
      if (panelEl.id) triggerEl.setAttribute("aria-controls", panelEl.id);
      if (!panelEl.getAttribute("role")) panelEl.setAttribute("role", "region");
      if (!panelEl.getAttribute("aria-label")) panelEl.setAttribute("aria-label", "Notifica\xE7\xF5es");
      this._handleTriggerClick = this._handleTriggerClick.bind(this);
      this._handlePanelClick = this._handlePanelClick.bind(this);
      triggerEl.addEventListener("click", this._handleTriggerClick);
      panelEl.addEventListener("click", this._handlePanelClick);
      this._removeEscapeListener = onEscapeKey(() => {
        if (this.isOpen) {
          this.close();
          this.triggerEl.focus();
        }
      });
      this._render();
      instances16.set(triggerEl, this);
    }
    static getInstance(el) {
      return instances16.get(el);
    }
    _ensureToastContainer() {
      let container = document.querySelector(".fs-toast-container");
      if (!container) {
        container = document.createElement("div");
        container.className = "fs-toast-container";
        document.body.appendChild(container);
      }
      return container;
    }
    _load() {
      if (this.storage !== "local") return [];
      try {
        const stored = JSON.parse(localStorage.getItem(this.storageKey));
        if (!Array.isArray(stored)) return [];
        return stored.filter((record) => record && typeof record === "object" && typeof record.id === "string" && record.id.length > 0).map((record) => ({
          id: record.id,
          title: typeof record.title === "string" ? record.title : "",
          body: typeof record.body === "string" ? record.body : "",
          variant: normalizeVariant(record.variant),
          time: Number.isFinite(record.time) && !Number.isNaN(new Date(record.time).getTime()) ? record.time : 0,
          read: record.read === true
        }));
      } catch (e) {
        return [];
      }
    }
    _persist() {
      if (this.storage !== "local") return;
      try {
        localStorage.setItem(this.storageKey, JSON.stringify(this.history));
      } catch (e) {
      }
    }
    get unreadCount() {
      return this.history.filter((record) => !record.read).length;
    }
    // Empilha um toast e registra a notificação. Retorna o id do registro.
    push(options = {}) {
      const { title = "", body = "", variant = "primary", autohide = true, delay } = options;
      do {
        idCounter7 += 1;
      } while (this.history.some((record2) => record2.id === `n-${idCounter7}`));
      const safeVariant = normalizeVariant(variant);
      const record = { id: `n-${idCounter7}`, title, body, variant: safeVariant, time: Date.now(), read: this.isOpen };
      this.history.unshift(record);
      this._persist();
      this._render();
      this._showToast({ title, body, variant: safeVariant, autohide, delay });
      this.triggerEl.dispatchEvent(new CustomEvent("fs:notification:pushed", { bubbles: true, detail: { record } }));
      return record.id;
    }
    _showToast({ title, body, variant, autohide, delay }) {
      const toastEl = document.createElement("div");
      toastEl.className = `fs-toast fs-toast-${normalizeVariant(variant)}`;
      if (delay != null) toastEl.setAttribute("data-delay", String(delay));
      if (!autohide) toastEl.setAttribute("data-autohide", "false");
      toastEl.innerHTML = `
      <div class="fs-toast-header">
        <span>${escapeHtml(title)}</span>
        <button type="button" class="fs-btn-close" data-fs-dismiss="toast" aria-label="Fechar"></button>
      </div>
      ${body ? `<div class="fs-toast-body">${escapeHtml(body)}</div>` : ""}
    `;
      this.toastContainer.appendChild(toastEl);
      const toast = new Toast(toastEl);
      toastEl.addEventListener("fs:toast:hidden", () => {
        toast.dispose();
        toastEl.remove();
      });
      toast.show();
    }
    remove(id) {
      this.history = this.history.filter((record) => record.id !== id);
      this._persist();
      this._render();
    }
    clear() {
      this.history = [];
      this._persist();
      this._render();
      this.triggerEl.dispatchEvent(new CustomEvent("fs:notification:cleared", { bubbles: true }));
    }
    getAll() {
      return this.history.slice();
    }
    _updateBadge() {
      if (!this.badgeEl) return;
      const count = this.unreadCount;
      this.badgeEl.textContent = String(count);
      this.badgeEl.toggleAttribute("hidden", count === 0);
    }
    _render() {
      var _a;
      if (this.listEl) {
        const items = this.history.map((record) => {
          const item = document.createElement("li");
          item.className = `fs-notification-item fs-notification-item-${normalizeVariant(record.variant)}${record.read ? "" : " fs-notification-item-unread"}`;
          item.dataset.id = record.id;
          item.innerHTML = `
          <div class="fs-notification-item-content">
            <p class="fs-notification-item-title">${escapeHtml(record.title)}</p>
            ${record.body ? `<p class="fs-notification-item-text">${escapeHtml(record.body)}</p>` : ""}
            <span class="fs-notification-item-time">${formatTime(record.time)}</span>
          </div>
          <button type="button" class="fs-btn-close" data-notification-dismiss aria-label="Remover notifica\xE7\xE3o"></button>`;
          return item;
        });
        this.listEl.replaceChildren(...items);
      }
      (_a = this.emptyEl) == null ? void 0 : _a.toggleAttribute("hidden", this.history.length > 0);
      this._updateBadge();
    }
    _handleTriggerClick(event) {
      event.preventDefault();
      this.toggle();
    }
    _handlePanelClick(event) {
      var _a;
      if (event.target.closest('[data-notification="clear"]')) {
        this.clear();
        return;
      }
      const dismissBtn = event.target.closest("[data-notification-dismiss]");
      if (dismissBtn) {
        const id = (_a = dismissBtn.closest(".fs-notification-item")) == null ? void 0 : _a.dataset.id;
        if (id) this.remove(id);
      }
    }
    open() {
      if (this.isOpen) return;
      this.isOpen = true;
      applyThemeContext(this.panelEl, this.triggerEl);
      this.panelEl.classList.add("is-open");
      const position = computePosition(this.triggerEl, this.panelEl, {
        placement: "bottom",
        align: "end",
        offset: 4
      });
      applyPosition(this.panelEl, position);
      this.triggerEl.setAttribute("aria-expanded", "true");
      this.history.forEach((record) => {
        record.read = true;
      });
      this._persist();
      this._render();
      this._outsideClickCleanup = onClickOutside(this.panelEl, (event) => {
        if (this.triggerEl.contains(event.target)) return;
        this.close();
      });
      this.triggerEl.dispatchEvent(new CustomEvent("fs:notification:opened", { bubbles: true }));
    }
    close() {
      var _a;
      if (!this.isOpen) return;
      this.isOpen = false;
      this.panelEl.classList.remove("is-open");
      this.panelEl.style.removeProperty("position");
      this.panelEl.style.removeProperty("top");
      this.panelEl.style.removeProperty("left");
      this.triggerEl.setAttribute("aria-expanded", "false");
      (_a = this._outsideClickCleanup) == null ? void 0 : _a.call(this);
      this._outsideClickCleanup = null;
      this.triggerEl.dispatchEvent(new CustomEvent("fs:notification:closed", { bubbles: true }));
    }
    toggle() {
      if (this.isOpen) {
        this.close();
      } else {
        this.open();
      }
    }
    dispose() {
      this.close();
      this._removeEscapeListener();
      this.triggerEl.removeEventListener("click", this._handleTriggerClick);
      this.panelEl.removeEventListener("click", this._handlePanelClick);
      instances16.delete(this.triggerEl);
    }
  };
  autoInit("notification-center", NotificationCenter);

  // packages/fokus-js/js/nested-menu.js
  var instances17 = createInstanceRegistry();
  function levelItems(menuEl) {
    const items = [];
    for (const child of menuEl.children) {
      let item = null;
      if (child.classList.contains("fs-dropdown-item")) {
        item = child;
      } else if (child.classList.contains("fs-dropdown-submenu")) {
        item = child.querySelector(":scope > .fs-dropdown-item");
      }
      if (item && !item.classList.contains("is-disabled")) items.push(item);
    }
    return items;
  }
  function isSubmenuParent(item) {
    return item.classList.contains("fs-dropdown-item-submenu");
  }
  var NestedMenu = class {
    constructor(toggleEl, options = {}) {
      var _a, _b, _c, _d;
      const targetSelector = toggleEl.getAttribute("data-fs-target");
      const rootMenu = targetSelector ? document.querySelector(targetSelector) : toggleEl.nextElementSibling;
      if (!rootMenu) {
        throw new Error("FokusStyles.NestedMenu: elemento do menu n\xE3o encontrado (data-fs-target).");
      }
      this.toggleEl = toggleEl;
      this.rootMenu = rootMenu;
      this.placement = (_b = (_a = options.placement) != null ? _a : toggleEl.getAttribute("data-placement")) != null ? _b : "bottom";
      this.align = (_d = (_c = options.align) != null ? _c : toggleEl.getAttribute("data-align")) != null ? _d : "start";
      this.isOpen = false;
      this._outsideClickCleanup = null;
      this._portal = createPortal(this.rootMenu, this.toggleEl);
      this._portal.mount();
      this.toggleEl.setAttribute("aria-haspopup", "menu");
      this.toggleEl.setAttribute("aria-expanded", "false");
      this.rootMenu.setAttribute("role", "menu");
      this.rootMenu.querySelectorAll(".fs-dropdown-menu").forEach((menu) => menu.setAttribute("role", "menu"));
      this.rootMenu.querySelectorAll(".fs-dropdown-item-submenu").forEach((item) => {
        item.setAttribute("aria-haspopup", "menu");
        item.setAttribute("aria-expanded", "false");
      });
      this._handleToggleClick = this._handleToggleClick.bind(this);
      this._handleMenuClick = this._handleMenuClick.bind(this);
      this._handleMenuKeydown = this._handleMenuKeydown.bind(this);
      this._handleMenuMouseover = this._handleMenuMouseover.bind(this);
      this.toggleEl.addEventListener("click", this._handleToggleClick);
      this.rootMenu.addEventListener("click", this._handleMenuClick);
      this.rootMenu.addEventListener("keydown", this._handleMenuKeydown);
      this.rootMenu.addEventListener("mouseover", this._handleMenuMouseover);
      this._removeEscapeListener = onEscapeKey((event) => {
        if (this.isOpen && isTopOverlay(this, event)) {
          this.hide();
          this.toggleEl.focus();
        }
      });
      instances17.set(toggleEl, this);
    }
    static getInstance(el) {
      return instances17.get(el);
    }
    _submenuOf(parentItem) {
      const submenu = parentItem.nextElementSibling;
      return submenu && submenu.classList.contains("fs-dropdown-menu") ? submenu : null;
    }
    _applyFlip(parentItem) {
      const submenu = this._submenuOf(parentItem);
      if (!submenu) return;
      const rect = parentItem.getBoundingClientRect();
      const width = submenu.offsetWidth || 180;
      const viewportWidth = document.documentElement.clientWidth;
      parentItem.parentElement.classList.toggle("fs-dropdown-submenu-left", rect.right + width > viewportWidth);
    }
    _openSubmenu(parentItem, focusFirst) {
      var _a;
      const submenu = this._submenuOf(parentItem);
      if (!submenu) return;
      const level = parentItem.closest(".fs-dropdown-menu");
      level.querySelectorAll(":scope > .fs-dropdown-submenu > .fs-dropdown-menu.is-open").forEach((sibling) => {
        var _a2;
        if (sibling !== submenu) {
          sibling.classList.remove("is-open");
          (_a2 = sibling.previousElementSibling) == null ? void 0 : _a2.setAttribute("aria-expanded", "false");
        }
      });
      this._applyFlip(parentItem);
      submenu.classList.add("is-open");
      parentItem.setAttribute("aria-expanded", "true");
      if (focusFirst) (_a = levelItems(submenu)[0]) == null ? void 0 : _a.focus();
    }
    _closeSubmenu(parentItem) {
      const submenu = this._submenuOf(parentItem);
      if (!submenu) return;
      submenu.querySelectorAll(".fs-dropdown-menu.is-open").forEach((nested) => {
        var _a;
        nested.classList.remove("is-open");
        (_a = nested.previousElementSibling) == null ? void 0 : _a.setAttribute("aria-expanded", "false");
      });
      submenu.classList.remove("is-open");
      parentItem.parentElement.classList.remove("fs-dropdown-submenu-left");
      parentItem.setAttribute("aria-expanded", "false");
    }
    _closeAllSubmenus() {
      this.rootMenu.querySelectorAll(".fs-dropdown-menu.is-open").forEach((submenu) => {
        var _a;
        submenu.classList.remove("is-open");
        (_a = submenu.previousElementSibling) == null ? void 0 : _a.setAttribute("aria-expanded", "false");
      });
      this.rootMenu.querySelectorAll(".fs-dropdown-submenu-left").forEach((wrapper) => wrapper.classList.remove("fs-dropdown-submenu-left"));
    }
    _handleToggleClick(event) {
      event.preventDefault();
      this.toggle();
    }
    _handleMenuClick(event) {
      var _a;
      const item = event.target.closest(".fs-dropdown-item");
      if (!item || item.classList.contains("is-disabled")) return;
      if (isSubmenuParent(item)) {
        event.preventDefault();
        if ((_a = this._submenuOf(item)) == null ? void 0 : _a.classList.contains("is-open")) {
          this._closeSubmenu(item);
        } else {
          this._openSubmenu(item, false);
        }
        return;
      }
      this.hide();
      this.toggleEl.focus();
    }
    _handleMenuMouseover(event) {
      const hoveredParent = event.target.closest(".fs-dropdown-item-submenu");
      this.rootMenu.querySelectorAll(".fs-dropdown-menu.is-open").forEach((submenu) => {
        var _a;
        if (!submenu.contains(event.target) && submenu !== (hoveredParent == null ? void 0 : hoveredParent.nextElementSibling)) {
          submenu.classList.remove("is-open");
          (_a = submenu.previousElementSibling) == null ? void 0 : _a.setAttribute("aria-expanded", "false");
        }
      });
      if (hoveredParent) this._applyFlip(hoveredParent);
    }
    _handleMenuKeydown(event) {
      var _a, _b, _c, _d;
      const active = document.activeElement;
      if (!((_a = active == null ? void 0 : active.classList) == null ? void 0 : _a.contains("fs-dropdown-item"))) return;
      const menu = active.closest(".fs-dropdown-menu");
      const items = levelItems(menu);
      const index = items.indexOf(active);
      const parentWrapper = menu.parentElement;
      const inSubmenu = parentWrapper == null ? void 0 : parentWrapper.classList.contains("fs-dropdown-submenu");
      switch (event.key) {
        case "ArrowDown":
          event.preventDefault();
          (_b = items[(index + 1) % items.length]) == null ? void 0 : _b.focus();
          break;
        case "ArrowUp":
          event.preventDefault();
          (_c = items[(index - 1 + items.length) % items.length]) == null ? void 0 : _c.focus();
          break;
        case "ArrowRight":
          if (isSubmenuParent(active)) {
            event.preventDefault();
            this._openSubmenu(active, true);
          }
          break;
        case "ArrowLeft":
          if (inSubmenu) {
            event.preventDefault();
            const parentItem = parentWrapper.querySelector(":scope > .fs-dropdown-item");
            this._closeSubmenu(parentItem);
            parentItem.focus();
          }
          break;
        case "Enter":
        case " ":
          if (isSubmenuParent(active)) {
            event.preventDefault();
            if ((_d = this._submenuOf(active)) == null ? void 0 : _d.classList.contains("is-open")) {
              this._closeSubmenu(active);
            } else {
              this._openSubmenu(active, true);
            }
          }
          break;
        case "Escape":
          event.stopPropagation();
          if (inSubmenu) {
            event.preventDefault();
            event.stopPropagation();
            const parentItem = parentWrapper.querySelector(":scope > .fs-dropdown-item");
            this._closeSubmenu(parentItem);
            parentItem.focus();
          } else {
            event.preventDefault();
            this.hide();
            this.toggleEl.focus();
          }
          break;
        default:
          break;
      }
    }
    show() {
      var _a;
      if (this.isOpen) return;
      this.isOpen = true;
      this._portal.mount();
      registerOverlay(this, this.rootMenu);
      applyThemeContext(this.rootMenu, this.toggleEl);
      this.rootMenu.classList.add("is-open");
      const position = computePosition(this.toggleEl, this.rootMenu, {
        placement: this.placement,
        align: this.align,
        offset: 4
      });
      applyPosition(this.rootMenu, position);
      this.toggleEl.setAttribute("aria-expanded", "true");
      (_a = levelItems(this.rootMenu)[0]) == null ? void 0 : _a.focus();
      this._outsideClickCleanup = onClickOutside(this.rootMenu, (event) => {
        if (!isTopOverlay(this, event) || this.toggleEl.contains(event.target)) return;
        this.hide();
      });
      this.toggleEl.dispatchEvent(new CustomEvent("fs:nested-menu:shown", { bubbles: true }));
    }
    hide() {
      var _a;
      if (!this.isOpen) return;
      this.isOpen = false;
      unregisterOverlay(this);
      this._closeAllSubmenus();
      this.rootMenu.classList.remove("is-open");
      this.rootMenu.style.removeProperty("position");
      this.rootMenu.style.removeProperty("top");
      this.rootMenu.style.removeProperty("left");
      this.toggleEl.setAttribute("aria-expanded", "false");
      (_a = this._outsideClickCleanup) == null ? void 0 : _a.call(this);
      this._outsideClickCleanup = null;
      this.toggleEl.dispatchEvent(new CustomEvent("fs:nested-menu:hidden", { bubbles: true }));
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      this.hide();
      this._removeEscapeListener();
      this.toggleEl.removeEventListener("click", this._handleToggleClick);
      this.rootMenu.removeEventListener("click", this._handleMenuClick);
      this.rootMenu.removeEventListener("keydown", this._handleMenuKeydown);
      this.rootMenu.removeEventListener("mouseover", this._handleMenuMouseover);
      this._portal.restore();
      instances17.delete(this.toggleEl);
    }
  };
  autoInit("nested-menu", NestedMenu);

  // packages/fokus-js/js/combobox.js
  var instances18 = createInstanceRegistry();
  var idCounter8 = 0;
  function uniqueId(prefix) {
    idCounter8 += 1;
    return `${prefix}-${idCounter8}`;
  }
  var Combobox = class {
    constructor(inputEl, options = {}) {
      var _a, _b;
      const targetSelector = inputEl.getAttribute("data-fs-target");
      const listboxEl = targetSelector ? document.querySelector(targetSelector) : inputEl.nextElementSibling;
      if (!listboxEl) {
        throw new Error("FokusStyles.Combobox: listbox n\xE3o encontrada (data-fs-target).");
      }
      this.inputEl = inputEl;
      this.listboxEl = listboxEl;
      this.placement = (_b = (_a = options.placement) != null ? _a : inputEl.getAttribute("data-placement")) != null ? _b : "bottom";
      this.isOpen = false;
      this.activeIndex = -1;
      this.value = null;
      this._outsideClickCleanup = null;
      if (!this.listboxEl.id) this.listboxEl.id = uniqueId("fs-combobox-listbox");
      this._portal = createPortal(this.listboxEl, this.inputEl);
      this._portal.mount();
      this.inputEl.setAttribute("role", "combobox");
      this.inputEl.setAttribute("aria-expanded", "false");
      this.inputEl.setAttribute("aria-autocomplete", "list");
      this.inputEl.setAttribute("aria-controls", this.listboxEl.id);
      this.inputEl.setAttribute("autocomplete", "off");
      this.listboxEl.setAttribute("role", "listbox");
      this._getOptions().forEach((option) => {
        if (!option.id) option.id = uniqueId("fs-combobox-option");
        option.setAttribute("role", "option");
        option.setAttribute("aria-selected", "false");
      });
      this._handleInput = this._handleInput.bind(this);
      this._handleKeydown = this._handleKeydown.bind(this);
      this._handleOptionMousedown = this._handleOptionMousedown.bind(this);
      this._handleOptionClick = this._handleOptionClick.bind(this);
      this.inputEl.addEventListener("input", this._handleInput);
      this.inputEl.addEventListener("keydown", this._handleKeydown);
      this.listboxEl.addEventListener("mousedown", this._handleOptionMousedown);
      this.listboxEl.addEventListener("click", this._handleOptionClick);
      this._removeEscapeListener = onEscapeKey((event) => {
        if (this.isOpen && isTopOverlay(this, event)) this.hide();
      });
      instances18.set(inputEl, this);
    }
    static getInstance(el) {
      return instances18.get(el);
    }
    _getOptions() {
      return Array.from(this.listboxEl.querySelectorAll(".fs-dropdown-item"));
    }
    _getSelectableOptions() {
      return this._getOptions().filter(
        (option) => !option.hidden && !option.hasAttribute("data-fs-empty") && !option.classList.contains("is-disabled")
      );
    }
    _filter(query) {
      const normalized = query.trim().toLowerCase();
      this._getOptions().forEach((option) => {
        if (option.hasAttribute("data-fs-empty")) return;
        option.hidden = normalized.length > 0 && !option.textContent.toLowerCase().includes(normalized);
      });
      const emptyEl = this.listboxEl.querySelector("[data-fs-empty]");
      if (emptyEl) emptyEl.hidden = this._getSelectableOptions().length > 0;
    }
    _setActiveIndex(index) {
      const options = this._getSelectableOptions();
      if (options.length === 0) {
        this.activeIndex = -1;
        this.inputEl.removeAttribute("aria-activedescendant");
        return;
      }
      const previous = options[this.activeIndex];
      previous == null ? void 0 : previous.classList.remove("is-active");
      this.activeIndex = (index % options.length + options.length) % options.length;
      const current = options[this.activeIndex];
      current.classList.add("is-active");
      current.scrollIntoView({ block: "nearest" });
      this.inputEl.setAttribute("aria-activedescendant", current.id);
    }
    _selectOption(option) {
      var _a;
      const label = option.textContent.trim();
      this.value = (_a = option.getAttribute("data-value")) != null ? _a : label;
      this.inputEl.value = label;
      this._getOptions().forEach((item) => item.setAttribute("aria-selected", String(item === option)));
      this.hide();
      this.inputEl.focus();
      this.inputEl.dispatchEvent(new Event("change", { bubbles: true }));
      this.inputEl.dispatchEvent(
        new CustomEvent("fs:combobox:changed", { bubbles: true, detail: { value: this.value, label } })
      );
    }
    _handleInput() {
      this._filter(this.inputEl.value);
      this.show();
      this._setActiveIndex(-1);
    }
    _handleKeydown(event) {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        if (!this.isOpen) {
          this.show();
        } else {
          this._setActiveIndex(this.activeIndex + 1);
        }
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        if (!this.isOpen) {
          this.show();
        } else {
          this._setActiveIndex(this.activeIndex - 1);
        }
      } else if (event.key === "Home" && this.isOpen) {
        event.preventDefault();
        this._setActiveIndex(0);
      } else if (event.key === "End" && this.isOpen) {
        event.preventDefault();
        this._setActiveIndex(this._getSelectableOptions().length - 1);
      } else if (event.key === "Enter") {
        if (this.isOpen && this.activeIndex >= 0) {
          event.preventDefault();
          this._selectOption(this._getSelectableOptions()[this.activeIndex]);
        }
      }
    }
    _handleOptionMousedown(event) {
      if (event.target.closest(".fs-dropdown-item")) event.preventDefault();
    }
    _handleOptionClick(event) {
      const option = event.target.closest(".fs-dropdown-item:not(.is-disabled)");
      if (!option || option.hasAttribute("data-fs-empty")) return;
      this._selectOption(option);
    }
    show() {
      if (this.isOpen) return;
      this.isOpen = true;
      this._portal.mount();
      registerOverlay(this, this.listboxEl);
      applyThemeContext(this.listboxEl, this.inputEl);
      this.listboxEl.classList.add("is-open");
      this.listboxEl.style.width = `${this.inputEl.offsetWidth}px`;
      const position = computePosition(this.inputEl, this.listboxEl, {
        placement: this.placement,
        align: "start",
        offset: 4
      });
      applyPosition(this.listboxEl, position);
      this.inputEl.setAttribute("aria-expanded", "true");
      this._outsideClickCleanup = onClickOutside(this.listboxEl, (event) => {
        if (!isTopOverlay(this, event) || this.inputEl.contains(event.target)) return;
        this.hide();
      });
      this.inputEl.dispatchEvent(new CustomEvent("fs:combobox:shown", { bubbles: true }));
    }
    hide() {
      var _a, _b;
      if (!this.isOpen) return;
      this.isOpen = false;
      unregisterOverlay(this);
      (_a = this._getSelectableOptions()[this.activeIndex]) == null ? void 0 : _a.classList.remove("is-active");
      this.activeIndex = -1;
      this.inputEl.removeAttribute("aria-activedescendant");
      this.listboxEl.classList.remove("is-open");
      this.listboxEl.style.removeProperty("position");
      this.listboxEl.style.removeProperty("top");
      this.listboxEl.style.removeProperty("left");
      this.listboxEl.style.removeProperty("width");
      this.inputEl.setAttribute("aria-expanded", "false");
      (_b = this._outsideClickCleanup) == null ? void 0 : _b.call(this);
      this._outsideClickCleanup = null;
      this.inputEl.dispatchEvent(new CustomEvent("fs:combobox:hidden", { bubbles: true }));
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      this.hide();
      this._removeEscapeListener();
      this.inputEl.removeEventListener("input", this._handleInput);
      this.inputEl.removeEventListener("keydown", this._handleKeydown);
      this.listboxEl.removeEventListener("mousedown", this._handleOptionMousedown);
      this.listboxEl.removeEventListener("click", this._handleOptionClick);
      this._portal.restore();
      instances18.delete(this.inputEl);
    }
  };
  autoInit("combobox", Combobox);

  // packages/fokus-js/js/datepicker.js
  var instances19 = createInstanceRegistry();
  var WEEKDAY_FORMATTER = new Intl.DateTimeFormat("pt-BR", { weekday: "narrow" });
  var HEADER_FORMATTER = new Intl.DateTimeFormat("pt-BR", { month: "long", year: "numeric" });
  var idCounter9 = 0;
  function uniqueId2(prefix) {
    idCounter9 += 1;
    return `${prefix}-${idCounter9}`;
  }
  function pad(value) {
    return String(value).padStart(2, "0");
  }
  function toISO(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }
  function toDisplay(date) {
    return `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
  }
  function parseDisplay(value) {
    const match = value.trim().match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (!match) return null;
    const [, day, month, year] = match;
    const date = new Date(Number(year), Number(month) - 1, Number(day));
    if (Number.isNaN(date.getTime())) return null;
    return date.getFullYear() === Number(year) && date.getMonth() === Number(month) - 1 && date.getDate() === Number(day) ? date : null;
  }
  function isSameDay(a, b) {
    return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
  }
  function addMonthsClamped(date, months) {
    const target = new Date(date.getFullYear(), date.getMonth() + months, 1);
    const lastDay = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate();
    return new Date(target.getFullYear(), target.getMonth(), Math.min(date.getDate(), lastDay));
  }
  function buildMonthCells(viewDate) {
    const year = viewDate.getFullYear();
    const month = viewDate.getMonth();
    const firstWeekday = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const daysInPrevMonth = new Date(year, month, 0).getDate();
    const cells = [];
    for (let i = firstWeekday - 1; i >= 0; i -= 1) {
      const day = daysInPrevMonth - i;
      const prevMonth = month === 0 ? 11 : month - 1;
      cells.push({ year: month === 0 ? year - 1 : year, month: prevMonth, day, outside: true });
    }
    for (let day = 1; day <= daysInMonth; day += 1) {
      cells.push({ year, month, day, outside: false });
    }
    let nextDay = 1;
    while (cells.length % 7 !== 0) {
      const nextMonth = month === 11 ? 0 : month + 1;
      cells.push({ year: month === 11 ? year + 1 : year, month: nextMonth, day: nextDay, outside: true });
      nextDay += 1;
    }
    return cells;
  }
  var Datepicker = class {
    constructor(inputEl, options = {}) {
      var _a, _b;
      const targetSelector = inputEl.getAttribute("data-fs-target");
      const panelEl = targetSelector ? document.querySelector(targetSelector) : inputEl.nextElementSibling;
      if (!panelEl) {
        throw new Error("FokusStyles.Datepicker: painel n\xE3o encontrado (data-fs-target).");
      }
      this.inputEl = inputEl;
      this.panelEl = panelEl;
      this.placement = (_b = (_a = options.placement) != null ? _a : inputEl.getAttribute("data-placement")) != null ? _b : "bottom";
      this.isOpen = false;
      this._suppressNextFocus = false;
      const initial = parseDisplay(inputEl.value);
      this.selectedDate = initial;
      this.viewDate = initial ? new Date(initial) : /* @__PURE__ */ new Date();
      this.value = initial ? toISO(initial) : null;
      this._outsideClickCleanup = null;
      this._positionCleanup = null;
      if (!this.panelEl.id) this.panelEl.id = uniqueId2("fs-datepicker-panel");
      this._portal = createPortal(this.panelEl, this.inputEl);
      this._portal.mount();
      this.inputEl.setAttribute("role", "combobox");
      this.inputEl.setAttribute("aria-haspopup", "grid");
      this.inputEl.setAttribute("aria-expanded", "false");
      this.inputEl.setAttribute("aria-controls", this.panelEl.id);
      this.inputEl.setAttribute("autocomplete", "off");
      this.panelEl.classList.add("fs-datepicker-panel");
      this.panelEl.innerHTML = '<div class="fs-datepicker-header"><button type="button" class="fs-datepicker-nav" data-fs-datepicker-prev aria-label="M\xEAs anterior">\u2039</button><span class="fs-datepicker-title" aria-live="polite"></span><button type="button" class="fs-datepicker-nav" data-fs-datepicker-next aria-label="Pr\xF3ximo m\xEAs">\u203A</button></div><div class="fs-datepicker-grid" role="grid"></div>';
      this.titleEl = this.panelEl.querySelector(".fs-datepicker-title");
      this.gridEl = this.panelEl.querySelector(".fs-datepicker-grid");
      this.prevBtn = this.panelEl.querySelector("[data-fs-datepicker-prev]");
      this.nextBtn = this.panelEl.querySelector("[data-fs-datepicker-next]");
      this._handleInputFocus = this._handleInputFocus.bind(this);
      this._handleInputKeydown = this._handleInputKeydown.bind(this);
      this._handleInputChange = this._handleInputChange.bind(this);
      this._handlePrev = this._handlePrev.bind(this);
      this._handleNext = this._handleNext.bind(this);
      this._handleGridClick = this._handleGridClick.bind(this);
      this._handleGridKeydown = this._handleGridKeydown.bind(this);
      this.inputEl.addEventListener("focus", this._handleInputFocus);
      this.inputEl.addEventListener("keydown", this._handleInputKeydown);
      this.inputEl.addEventListener("change", this._handleInputChange);
      this.prevBtn.addEventListener("click", this._handlePrev);
      this.nextBtn.addEventListener("click", this._handleNext);
      this.gridEl.addEventListener("click", this._handleGridClick);
      this.gridEl.addEventListener("keydown", this._handleGridKeydown);
      this._removeEscapeListener = onEscapeKey((event) => {
        if (this.isOpen && isTopOverlay(this, event)) {
          this.hide();
          this._refocusInputWithoutReopening();
        }
      });
      instances19.set(inputEl, this);
    }
    static getInstance(el) {
      return instances19.get(el);
    }
    _handleInputFocus() {
      if (this._suppressNextFocus) {
        this._suppressNextFocus = false;
        return;
      }
      this.show();
    }
    _refocusInputWithoutReopening() {
      this._suppressNextFocus = true;
      this.inputEl.focus();
    }
    // O foco fica no input ao abrir (ver `_handleInputFocus`) — sem este
    // handler, `ArrowDown`/`ArrowUp` não teriam efeito nenhum, porque o
    // keydown do grid (`_handleGridKeydown`) só reage quando o foco real já
    // está num botão de dia. `ArrowDown` move o foco pro dia "tabável";
    // depois disso, o keydown do próprio grid assume a navegação.
    _handleInputKeydown(event) {
      if (event.key !== "ArrowDown" && event.key !== "ArrowUp") return;
      event.preventDefault();
      if (!this.isOpen) this.show();
      this._focusTabbable();
    }
    _handleInputChange() {
      const parsed = parseDisplay(this.inputEl.value);
      if (!parsed) {
        this.selectedDate = null;
        this.value = null;
        if (this.isOpen) this._render();
        return;
      }
      this.selectedDate = parsed;
      this.viewDate = new Date(parsed);
      this.value = toISO(parsed);
      if (this.isOpen) this._render();
    }
    _handlePrev(event) {
      event.preventDefault();
      this.viewDate = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() - 1, 1);
      this._render();
    }
    _handleNext(event) {
      event.preventDefault();
      this.viewDate = new Date(this.viewDate.getFullYear(), this.viewDate.getMonth() + 1, 1);
      this._render();
    }
    _handleGridClick(event) {
      const day = event.target.closest(".fs-datepicker-day:not(:disabled)");
      if (!day) return;
      this._selectDate(new Date(Number(day.dataset.year), Number(day.dataset.month), Number(day.dataset.day)));
    }
    _handleGridKeydown(event) {
      const current = event.target.closest(".fs-datepicker-day");
      if (!current) return;
      const currentDate = new Date(Number(current.dataset.year), Number(current.dataset.month), Number(current.dataset.day));
      let nextDate = null;
      switch (event.key) {
        case "ArrowRight":
          nextDate = new Date(currentDate);
          nextDate.setDate(nextDate.getDate() + 1);
          break;
        case "ArrowLeft":
          nextDate = new Date(currentDate);
          nextDate.setDate(nextDate.getDate() - 1);
          break;
        case "ArrowDown":
          nextDate = new Date(currentDate);
          nextDate.setDate(nextDate.getDate() + 7);
          break;
        case "ArrowUp":
          nextDate = new Date(currentDate);
          nextDate.setDate(nextDate.getDate() - 7);
          break;
        case "Home":
          nextDate = new Date(currentDate);
          nextDate.setDate(nextDate.getDate() - nextDate.getDay());
          break;
        case "End":
          nextDate = new Date(currentDate);
          nextDate.setDate(nextDate.getDate() + (6 - nextDate.getDay()));
          break;
        case "PageUp":
          nextDate = event.shiftKey ? addMonthsClamped(currentDate, -12) : addMonthsClamped(currentDate, -1);
          break;
        case "PageDown":
          nextDate = event.shiftKey ? addMonthsClamped(currentDate, 12) : addMonthsClamped(currentDate, 1);
          break;
        case "Enter":
        case " ":
          event.preventDefault();
          this._selectDate(currentDate);
          return;
        default:
          return;
      }
      event.preventDefault();
      this._moveTo(nextDate);
    }
    _moveTo(date) {
      const sameMonth = date.getFullYear() === this.viewDate.getFullYear() && date.getMonth() === this.viewDate.getMonth();
      if (!sameMonth) this.viewDate = new Date(date.getFullYear(), date.getMonth(), 1);
      this._render(date);
      this._focusTabbable();
    }
    _selectDate(date) {
      this.selectedDate = date;
      this.viewDate = new Date(date);
      this.value = toISO(date);
      this.inputEl.value = toDisplay(date);
      this.hide();
      this._refocusInputWithoutReopening();
      this.inputEl.dispatchEvent(new Event("change", { bubbles: true }));
      this.inputEl.dispatchEvent(
        new CustomEvent("fs:datepicker:changed", { bubbles: true, detail: { value: this.value, date: new Date(date) } })
      );
    }
    _focusTabbable() {
      var _a;
      (_a = this.gridEl.querySelector('.fs-datepicker-day[tabindex="0"]')) == null ? void 0 : _a.focus();
    }
    _render(preferredDate) {
      var _a, _b;
      this.titleEl.textContent = HEADER_FORMATTER.format(this.viewDate);
      const cells = buildMonthCells(this.viewDate);
      const today = /* @__PURE__ */ new Date();
      const focusTarget = (_a = preferredDate != null ? preferredDate : this.selectedDate) != null ? _a : today;
      let tabbable = cells.find((cell) => !cell.outside && isSameDay(new Date(cell.year, cell.month, cell.day), focusTarget));
      if (!tabbable) tabbable = (_b = cells.find((cell) => !cell.outside)) != null ? _b : cells[0];
      const weekdaysHtml = Array.from({ length: 7 }, (_, i) => {
        const base = new Date(2024, 0, 7 + i);
        return `<div class="fs-datepicker-weekday" role="columnheader">${WEEKDAY_FORMATTER.format(base)}</div>`;
      }).join("");
      const rows = [];
      for (let i = 0; i < cells.length; i += 7) rows.push(cells.slice(i, i + 7));
      const weeksHtml = rows.map((row) => {
        const rowHtml = row.map((cell) => {
          const cellDate = new Date(cell.year, cell.month, cell.day);
          const isSelected = this.selectedDate ? isSameDay(cellDate, this.selectedDate) : false;
          const isToday = isSameDay(cellDate, today);
          const isTabbable = cell === tabbable;
          const classes = ["fs-datepicker-day"];
          if (cell.outside) classes.push("is-outside");
          if (isToday) classes.push("is-today");
          if (isSelected) classes.push("is-selected");
          return `<button type="button" class="${classes.join(" ")}" role="gridcell" tabindex="${isTabbable ? "0" : "-1"}" aria-selected="${isSelected}" ${isToday ? 'aria-current="date" ' : ""}data-year="${cell.year}" data-month="${cell.month}" data-day="${cell.day}">${cell.day}</button>`;
        }).join("");
        return `<div class="fs-datepicker-week" role="row">${rowHtml}</div>`;
      }).join("");
      this.gridEl.innerHTML = `<div class="fs-datepicker-weekdays" role="row">${weekdaysHtml}</div>${weeksHtml}`;
    }
    show() {
      var _a;
      if (this.isOpen) return;
      this.isOpen = true;
      this._portal.mount();
      registerOverlay(this, this.panelEl);
      applyThemeContext(this.panelEl, this.inputEl);
      this.panelEl.classList.add("is-open");
      const position = computePosition(this.inputEl, this.panelEl, {
        placement: this.placement,
        align: "start",
        offset: 4
      });
      applyPosition(this.panelEl, position);
      this._positionCleanup = watchPosition(this.inputEl, this.panelEl, {
        placement: this.placement,
        align: "start",
        offset: 4
      });
      this.inputEl.setAttribute("aria-expanded", "true");
      this._render((_a = this.selectedDate) != null ? _a : /* @__PURE__ */ new Date());
      this._outsideClickCleanup = onClickOutside(this.panelEl, (event) => {
        if (!isTopOverlay(this, event) || this.inputEl.contains(event.target)) return;
        this.hide();
      });
      this.inputEl.dispatchEvent(new CustomEvent("fs:datepicker:shown", { bubbles: true }));
    }
    hide() {
      var _a, _b;
      if (!this.isOpen) return;
      this.isOpen = false;
      unregisterOverlay(this);
      this.panelEl.classList.remove("is-open");
      this.panelEl.style.removeProperty("position");
      this.panelEl.style.removeProperty("top");
      this.panelEl.style.removeProperty("left");
      (_a = this._positionCleanup) == null ? void 0 : _a.call(this);
      this._positionCleanup = null;
      this.inputEl.setAttribute("aria-expanded", "false");
      (_b = this._outsideClickCleanup) == null ? void 0 : _b.call(this);
      this._outsideClickCleanup = null;
      this.inputEl.dispatchEvent(new CustomEvent("fs:datepicker:hidden", { bubbles: true }));
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      this.hide();
      this._removeEscapeListener();
      this.inputEl.removeEventListener("focus", this._handleInputFocus);
      this.inputEl.removeEventListener("keydown", this._handleInputKeydown);
      this.inputEl.removeEventListener("change", this._handleInputChange);
      this.prevBtn.removeEventListener("click", this._handlePrev);
      this.nextBtn.removeEventListener("click", this._handleNext);
      this.gridEl.removeEventListener("click", this._handleGridClick);
      this.gridEl.removeEventListener("keydown", this._handleGridKeydown);
      this._portal.restore();
      instances19.delete(this.inputEl);
    }
  };
  autoInit("datepicker", Datepicker);

  // packages/fokus-js/js/datatable.js
  var instances20 = createInstanceRegistry();
  var PAGE_WINDOW = 5;
  var ICON_CHEVRON_LEFT = '<svg class="fs-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';
  var ICON_CHEVRON_RIGHT = '<svg class="fs-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>';
  function compareValues(a, b) {
    const numA = Number(a);
    const numB = Number(b);
    if (a !== "" && b !== "" && !Number.isNaN(numA) && !Number.isNaN(numB)) {
      return numA - numB;
    }
    return a.localeCompare(b, "pt-BR", { sensitivity: "base" });
  }
  function compareTypedValues(a, b, type) {
    if (type === "date") {
      return new Date(a).getTime() - new Date(b).getTime();
    }
    if (type === "number" || type === "currency") {
      const normalize = (value) => {
        const text = String(value).replace(/[^\d.,+-]/g, "");
        return Number(text.includes(",") ? text.replace(/\./g, "").replace(",", ".") : text);
      };
      return normalize(a) - normalize(b);
    }
    return compareValues(a, b);
  }
  var DataTable = class {
    constructor(rootEl, options = {}) {
      var _a, _b;
      const tableEl = rootEl.querySelector(".fs-table");
      if (!tableEl) {
        throw new Error("FokusStyles.DataTable: tabela n\xE3o encontrada (.fs-table).");
      }
      const tbodyEl = tableEl.querySelector("tbody");
      if (!tbodyEl) {
        throw new Error("FokusStyles.DataTable: <tbody> n\xE3o encontrado.");
      }
      this.rootEl = rootEl;
      this.tableEl = tableEl;
      this.theadEl = tableEl.querySelector("thead");
      this.tbodyEl = tbodyEl;
      const configuredPageSize = Number((_b = (_a = rootEl.getAttribute("data-fs-page-size")) != null ? _a : options.pageSize) != null ? _b : 10);
      this.pageSize = Number.isFinite(configuredPageSize) && configuredPageSize > 0 ? Math.floor(configuredPageSize) : 10;
      this.filterInput = rootEl.querySelector("[data-fs-datatable-filter]");
      this.emptyEl = rootEl.querySelector("[data-fs-datatable-empty]");
      this.loadingEl = rootEl.querySelector("[data-fs-datatable-loading]");
      this.errorEl = rootEl.querySelector("[data-fs-datatable-error]");
      this.paginationEl = rootEl.querySelector("[data-fs-datatable-pagination]");
      if (!this.paginationEl) {
        this.paginationEl = document.createElement("nav");
        this.paginationEl.setAttribute("data-fs-datatable-pagination", "");
        this.paginationEl.setAttribute("aria-label", "Pagina\xE7\xE3o da tabela");
        rootEl.appendChild(this.paginationEl);
      }
      this.sortKey = null;
      this.sortDirection = "none";
      this.filterQuery = "";
      this.currentPage = 1;
      this._loading = false;
      this._error = null;
      this._rows = [];
      this._sortButtons = [];
      this._buildSortHeaders();
      this._readRows();
      this._handleSortClick = this._handleSortClick.bind(this);
      this._handleFilterInput = this._handleFilterInput.bind(this);
      this._handlePaginationClick = this._handlePaginationClick.bind(this);
      this._handleCellKeydown = this._handleCellKeydown.bind(this);
      this._handleCellFocusIn = this._handleCellFocusIn.bind(this);
      if (this.theadEl) this.theadEl.addEventListener("click", this._handleSortClick);
      if (this.filterInput) this.filterInput.addEventListener("input", this._handleFilterInput);
      this.paginationEl.addEventListener("click", this._handlePaginationClick);
      this.tbodyEl.addEventListener("keydown", this._handleCellKeydown);
      this.tbodyEl.addEventListener("focusin", this._handleCellFocusIn);
      this._render();
      this.tableEl.setAttribute("aria-busy", "false");
      instances20.set(rootEl, this);
    }
    static getInstance(el) {
      return instances20.get(el);
    }
    get pageCount() {
      return Math.max(1, Math.ceil(this._matchedRows().length / this.pageSize));
    }
    get rowCount() {
      return this._matchedRows().length;
    }
    _buildSortHeaders() {
      if (!this.theadEl) return;
      this._sortButtons = Array.from(this.theadEl.querySelectorAll("th[data-fs-sort]")).map((th) => {
        const key = th.getAttribute("data-fs-sort");
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "fs-datatable-sort-btn";
        btn.setAttribute("data-fs-sort-key", key);
        while (th.firstChild) btn.appendChild(th.firstChild);
        th.appendChild(btn);
        th.setAttribute("aria-sort", "none");
        return { th, key, btn };
      });
    }
    _readRows() {
      this._rows = Array.from(this.tbodyEl.querySelectorAll(":scope > tr")).map((el, order) => ({
        el,
        order,
        cells: Array.from(el.children).map((cell) => {
          var _a;
          return ((_a = cell.getAttribute("data-fs-sort-value")) != null ? _a : cell.textContent).trim();
        })
      }));
    }
    refresh() {
      this._readRows();
      this.currentPage = 1;
      this._render();
    }
    _matchedRows() {
      var _a, _b;
      let rows = this._rows;
      if (this.filterQuery) {
        const query = this.filterQuery.toLowerCase();
        rows = rows.filter((row) => row.cells.some((cell) => cell.toLowerCase().includes(query)));
      }
      if (this.sortKey && this.sortDirection !== "none") {
        const th = (_a = this._sortButtons.find((entry) => entry.key === this.sortKey)) == null ? void 0 : _a.th;
        const columnIndex = th ? Array.from(this.theadEl.querySelectorAll("th")).indexOf(th) : -1;
        const type = (_b = th == null ? void 0 : th.getAttribute("data-fs-sort-type")) != null ? _b : "text";
        if (columnIndex >= 0) {
          const direction = this.sortDirection === "asc" ? 1 : -1;
          rows = [...rows].sort((a, b) => {
            var _a2, _b2;
            return direction * compareTypedValues((_a2 = a.cells[columnIndex]) != null ? _a2 : "", (_b2 = b.cells[columnIndex]) != null ? _b2 : "", type);
          });
        }
      } else {
        rows = [...rows].sort((a, b) => a.order - b.order);
      }
      return rows;
    }
    _nextDirection(key) {
      if (this.sortKey !== key) return "asc";
      if (this.sortDirection === "asc") return "desc";
      if (this.sortDirection === "desc") return "none";
      return "asc";
    }
    sort(key, direction = "asc") {
      this.sortKey = direction === "none" ? null : key;
      this.sortDirection = direction;
      this.currentPage = 1;
      this._render();
      this.rootEl.dispatchEvent(
        new CustomEvent("fs:datatable:sorted", { bubbles: true, detail: { key: this.sortKey, direction: this.sortDirection } })
      );
    }
    filter(query) {
      this.filterQuery = (query != null ? query : "").trim();
      if (this.filterInput) this.filterInput.value = this.filterQuery;
      this.currentPage = 1;
      this._render();
      this.rootEl.dispatchEvent(
        new CustomEvent("fs:datatable:filtered", { bubbles: true, detail: { query: this.filterQuery, matched: this.rowCount } })
      );
    }
    goToPage(page) {
      const pageCount = this.pageCount;
      const requested = Number(page);
      const next = Number.isFinite(requested) ? Math.min(Math.max(1, Math.floor(requested)), pageCount) : 1;
      if (next === this.currentPage) return;
      this.currentPage = next;
      this._render();
      this.rootEl.dispatchEvent(new CustomEvent("fs:datatable:paged", { bubbles: true, detail: { page: this.currentPage, pageCount } }));
    }
    setLoading(isLoading) {
      this._loading = Boolean(isLoading);
      this.tableEl.setAttribute("aria-busy", String(this._loading));
      if (this._loading) this._error = null;
      this._syncVisibility();
    }
    setError(message) {
      var _a;
      this._error = message || null;
      if (this._error) this._loading = false;
      this.tableEl.setAttribute("aria-busy", String(this._loading));
      if (this.errorEl) {
        const messageEl = this.errorEl.querySelector("[data-fs-datatable-error-message]");
        if (messageEl) messageEl.textContent = (_a = this._error) != null ? _a : "";
      }
      this._syncVisibility();
    }
    _syncVisibility() {
      const showLoading = this._loading;
      const showError = !this._loading && Boolean(this._error);
      const showTable = !showLoading && !showError;
      this.tableEl.hidden = !showTable;
      if (this.filterInput) this.filterInput.disabled = !showTable;
      if (this.loadingEl) this.loadingEl.hidden = !showLoading;
      if (this.errorEl) this.errorEl.hidden = !showError;
      if (!showTable) {
        this.paginationEl.hidden = true;
        if (this.emptyEl) this.emptyEl.hidden = true;
      } else {
        this._render();
      }
    }
    _render() {
      if (this._loading || this._error) return;
      const matched = this._matchedRows();
      const pageCount = Math.max(1, Math.ceil(matched.length / this.pageSize));
      this.currentPage = Math.min(Math.max(1, this.currentPage), pageCount);
      const start = (this.currentPage - 1) * this.pageSize;
      const pageRows = matched.slice(start, start + this.pageSize);
      pageRows.forEach((row) => this.tbodyEl.appendChild(row.el));
      this._rows.forEach((row) => {
        row.el.hidden = !pageRows.includes(row);
      });
      const isEmpty = matched.length === 0;
      this.tableEl.hidden = isEmpty && Boolean(this.emptyEl);
      if (this.emptyEl) this.emptyEl.hidden = !isEmpty;
      this._renderPagination(pageCount);
      this._syncAriaSort();
      this._resetRovingTabindex();
    }
    _syncAriaSort() {
      this._sortButtons.forEach(({ th, key }) => {
        const direction = key === this.sortKey ? this.sortDirection : "none";
        th.setAttribute("aria-sort", direction === "asc" ? "ascending" : direction === "desc" ? "descending" : "none");
      });
    }
    _renderPagination(pageCount) {
      this.paginationEl.hidden = pageCount <= 1;
      if (pageCount <= 1) {
        this.paginationEl.innerHTML = "";
        return;
      }
      const end = Math.min(pageCount, Math.max(PAGE_WINDOW, this.currentPage + Math.floor(PAGE_WINDOW / 2)));
      const start = Math.max(1, end - PAGE_WINDOW + 1);
      const items = [this._pageItem(ICON_CHEVRON_LEFT, this.currentPage - 1, this.currentPage === 1, "P\xE1gina anterior")];
      for (let page = start; page <= end; page += 1) {
        items.push(this._pageItem(String(page), page, false, `P\xE1gina ${page}`, page === this.currentPage));
      }
      items.push(this._pageItem(ICON_CHEVRON_RIGHT, this.currentPage + 1, this.currentPage === pageCount, "Pr\xF3xima p\xE1gina"));
      this.paginationEl.innerHTML = `<ul class="fs-pagination">${items.join("")}</ul>`;
    }
    _pageItem(label, page, disabled, ariaLabel, isActive = false) {
      const classes = ["fs-page-item"];
      if (disabled) classes.push("is-disabled");
      if (isActive) classes.push("is-active");
      return `<li class="${classes.join(" ")}"><button type="button" class="fs-page-link" data-fs-page="${page}" aria-label="${ariaLabel}"${isActive ? ' aria-current="page"' : ""}${disabled ? " disabled" : ""}>${label}</button></li>`;
    }
    _handleSortClick(event) {
      const btn = event.target.closest("[data-fs-sort-key]");
      if (!btn) return;
      const key = btn.dataset.fsSortKey;
      this.sort(key, this._nextDirection(key));
    }
    _handleFilterInput() {
      this.filter(this.filterInput.value);
    }
    _handlePaginationClick(event) {
      const btn = event.target.closest("[data-fs-page]");
      if (!btn || btn.disabled) return;
      this.goToPage(Number(btn.dataset.fsPage));
    }
    _visibleCells() {
      return Array.from(this.tbodyEl.querySelectorAll("tr:not([hidden]) > td, tr:not([hidden]) > th"));
    }
    _resetRovingTabindex() {
      this._visibleCells().forEach((cell, index) => cell.setAttribute("tabindex", index === 0 ? "0" : "-1"));
    }
    _handleCellFocusIn(event) {
      const cell = event.target.closest("td, th");
      if (!cell || cell.parentElement.hidden) return;
      this._visibleCells().forEach((c) => c.setAttribute("tabindex", c === cell ? "0" : "-1"));
    }
    _handleCellKeydown(event) {
      var _a, _b, _c;
      const cell = event.target.closest("td, th");
      if (!cell) return;
      const rows = Array.from(this.tbodyEl.querySelectorAll("tr:not([hidden])"));
      const rowIndex = rows.indexOf(cell.parentElement);
      if (rowIndex === -1) return;
      const cellsInRow = Array.from(cell.parentElement.children);
      const colIndex = cellsInRow.indexOf(cell);
      const lastRow = rows[rows.length - 1];
      let target = null;
      switch (event.key) {
        case "ArrowRight":
          target = cellsInRow[colIndex + 1];
          break;
        case "ArrowLeft":
          target = cellsInRow[colIndex - 1];
          break;
        case "ArrowDown":
          target = (_a = rows[rowIndex + 1]) == null ? void 0 : _a.children[colIndex];
          break;
        case "ArrowUp":
          target = (_b = rows[rowIndex - 1]) == null ? void 0 : _b.children[colIndex];
          break;
        case "Home":
          target = event.ctrlKey ? (_c = rows[0]) == null ? void 0 : _c.children[0] : cellsInRow[0];
          break;
        case "End":
          target = event.ctrlKey ? lastRow == null ? void 0 : lastRow.children[lastRow.children.length - 1] : cellsInRow[cellsInRow.length - 1];
          break;
        default:
          return;
      }
      if (!target) return;
      event.preventDefault();
      target.focus();
    }
    dispose() {
      if (this.theadEl) this.theadEl.removeEventListener("click", this._handleSortClick);
      if (this.filterInput) this.filterInput.removeEventListener("input", this._handleFilterInput);
      this.paginationEl.removeEventListener("click", this._handlePaginationClick);
      this.tbodyEl.removeEventListener("keydown", this._handleCellKeydown);
      this.tbodyEl.removeEventListener("focusin", this._handleCellFocusIn);
      this._sortButtons.forEach(({ th, btn }) => {
        while (btn.firstChild) th.insertBefore(btn.firstChild, btn);
        btn.remove();
        th.removeAttribute("aria-sort");
      });
      this._rows.forEach(({ el }) => {
        el.hidden = false;
        this.tbodyEl.appendChild(el);
      });
      instances20.delete(this.rootEl);
    }
  };
  autoInit("datatable", DataTable);

  // packages/fokus-js/js/command-palette.js
  var instances21 = createInstanceRegistry();
  var idCounter10 = 0;
  function uniqueId3(prefix) {
    idCounter10 += 1;
    return `${prefix}-${idCounter10}`;
  }
  function parseShortcut(spec) {
    const parts = spec.toLowerCase().split("+").map((part) => part.trim());
    return {
      mod: parts.includes("mod"),
      shift: parts.includes("shift"),
      alt: parts.includes("alt"),
      key: parts[parts.length - 1]
    };
  }
  function matchesShortcut(event, shortcut) {
    const modPressed = event.ctrlKey || event.metaKey;
    return event.key.toLowerCase() === shortcut.key && shortcut.mod === modPressed && shortcut.shift === event.shiftKey && shortcut.alt === event.altKey;
  }
  var CommandPalette = class {
    constructor(triggerEl, options = {}) {
      var _a, _b;
      const targetSelector = triggerEl.getAttribute("data-fs-target");
      const paletteEl = targetSelector ? document.querySelector(targetSelector) : null;
      if (!paletteEl) {
        throw new Error("FokusStyles.CommandPalette: painel n\xE3o encontrado (data-fs-target).");
      }
      this.triggerEl = triggerEl;
      this.paletteEl = paletteEl;
      this.dialogEl = (_a = paletteEl.querySelector(".fs-command-palette-dialog")) != null ? _a : paletteEl;
      this.inputEl = paletteEl.querySelector(".fs-command-palette-input");
      this.listEl = paletteEl.querySelector(".fs-command-palette-list");
      this.isOpen = false;
      this.activeIndex = -1;
      this._focusTrap = null;
      this._outsideClickCleanup = null;
      if (!this.listEl.id) this.listEl.id = uniqueId3("fs-command-palette-listbox");
      paletteEl.setAttribute("role", "dialog");
      paletteEl.setAttribute("aria-modal", "true");
      this.inputEl.setAttribute("role", "combobox");
      this.inputEl.setAttribute("aria-expanded", "false");
      this.inputEl.setAttribute("aria-autocomplete", "list");
      this.inputEl.setAttribute("aria-controls", this.listEl.id);
      this.inputEl.setAttribute("autocomplete", "off");
      this.listEl.setAttribute("role", "listbox");
      this._getItems().forEach((item) => {
        if (!item.id) item.id = uniqueId3("fs-command-palette-option");
        item.setAttribute("role", "option");
        item.setAttribute("aria-selected", "false");
      });
      this._handleTriggerClick = this._handleTriggerClick.bind(this);
      this._handleInput = this._handleInput.bind(this);
      this._handleKeydown = this._handleKeydown.bind(this);
      this._handleItemMousedown = this._handleItemMousedown.bind(this);
      this._handleItemClick = this._handleItemClick.bind(this);
      this._handleGlobalShortcut = this._handleGlobalShortcut.bind(this);
      triggerEl.addEventListener("click", this._handleTriggerClick);
      this.inputEl.addEventListener("input", this._handleInput);
      this.inputEl.addEventListener("keydown", this._handleKeydown);
      this.listEl.addEventListener("mousedown", this._handleItemMousedown);
      this.listEl.addEventListener("click", this._handleItemClick);
      const shortcut = (_b = options.shortcut) != null ? _b : triggerEl.getAttribute("data-fs-shortcut");
      this._shortcut = shortcut ? parseShortcut(shortcut) : null;
      if (this._shortcut) document.addEventListener("keydown", this._handleGlobalShortcut);
      this._removeEscapeListener = onEscapeKey((event) => {
        if (this.isOpen && isTopOverlay(this, event)) this.hide();
      });
      instances21.set(triggerEl, this);
    }
    static getInstance(el) {
      return instances21.get(el);
    }
    _getItems() {
      return Array.from(this.listEl.querySelectorAll(".fs-dropdown-item"));
    }
    _getSelectableItems() {
      return this._getItems().filter((item) => !item.hidden && !item.hasAttribute("data-fs-empty") && !item.classList.contains("is-disabled"));
    }
    _filter(query) {
      const normalized = query.trim().toLowerCase();
      this._getItems().forEach((item) => {
        if (item.hasAttribute("data-fs-empty")) return;
        item.hidden = normalized.length > 0 && !item.textContent.toLowerCase().includes(normalized);
      });
      const emptyEl = this.listEl.querySelector("[data-fs-empty]");
      if (emptyEl) emptyEl.hidden = this._getSelectableItems().length > 0;
    }
    _setActiveIndex(index) {
      const items = this._getSelectableItems();
      if (items.length === 0) {
        this.activeIndex = -1;
        this.inputEl.removeAttribute("aria-activedescendant");
        return;
      }
      const previous = items[this.activeIndex];
      previous == null ? void 0 : previous.classList.remove("is-active");
      this.activeIndex = (index % items.length + items.length) % items.length;
      const current = items[this.activeIndex];
      current.classList.add("is-active");
      current.scrollIntoView({ block: "nearest" });
      this.inputEl.setAttribute("aria-activedescendant", current.id);
    }
    _selectItem(item) {
      var _a;
      const label = item.textContent.trim();
      const value = (_a = item.getAttribute("data-value")) != null ? _a : label;
      this.hide();
      this.triggerEl.dispatchEvent(
        new CustomEvent("fs:command-palette:selected", { bubbles: true, detail: { value, label } })
      );
    }
    _handleTriggerClick(event) {
      event.preventDefault();
      this.toggle();
    }
    _handleInput() {
      this._filter(this.inputEl.value);
      this._setActiveIndex(0);
    }
    _handleKeydown(event) {
      if (event.key === "ArrowDown") {
        event.preventDefault();
        this._setActiveIndex(this.activeIndex + 1);
      } else if (event.key === "ArrowUp") {
        event.preventDefault();
        this._setActiveIndex(this.activeIndex - 1);
      } else if (event.key === "Enter") {
        if (this.activeIndex >= 0) {
          event.preventDefault();
          this._selectItem(this._getSelectableItems()[this.activeIndex]);
        }
      }
    }
    _handleItemMousedown(event) {
      if (event.target.closest(".fs-dropdown-item")) event.preventDefault();
    }
    _handleItemClick(event) {
      const item = event.target.closest(".fs-dropdown-item:not(.is-disabled)");
      if (!item || item.hasAttribute("data-fs-empty")) return;
      this._selectItem(item);
    }
    _handleGlobalShortcut(event) {
      if (!matchesShortcut(event, this._shortcut)) return;
      event.preventDefault();
      this.toggle();
    }
    show() {
      if (this.isOpen) return;
      this.isOpen = true;
      registerOverlay(this, this.dialogEl);
      applyThemeContext(this.paletteEl, this.triggerEl);
      this.paletteEl.classList.add("is-open");
      lockScroll();
      this.inputEl.value = "";
      this._filter("");
      this._setActiveIndex(0);
      this.inputEl.setAttribute("aria-expanded", "true");
      this._focusTrap = createFocusTrap(this.dialogEl);
      this._focusTrap.activate();
      this._outsideClickCleanup = onClickOutside(this.dialogEl, (event) => {
        if (isTopOverlay(this, event)) this.hide();
      });
      this.triggerEl.dispatchEvent(new CustomEvent("fs:command-palette:shown", { bubbles: true }));
    }
    hide() {
      var _a, _b;
      if (!this.isOpen) return;
      this.isOpen = false;
      const wasTop = unregisterOverlay(this);
      this.paletteEl.classList.remove("is-open");
      unlockScroll();
      this.inputEl.setAttribute("aria-expanded", "false");
      (_a = this._focusTrap) == null ? void 0 : _a.deactivate();
      this._focusTrap = null;
      (_b = this._outsideClickCleanup) == null ? void 0 : _b.call(this);
      this._outsideClickCleanup = null;
      if (wasTop && this.triggerEl.isConnected && !this.triggerEl.closest("[inert]")) this.triggerEl.focus();
      this.triggerEl.dispatchEvent(new CustomEvent("fs:command-palette:hidden", { bubbles: true }));
    }
    toggle() {
      if (this.isOpen) {
        this.hide();
      } else {
        this.show();
      }
    }
    dispose() {
      this.hide();
      this._removeEscapeListener();
      this.triggerEl.removeEventListener("click", this._handleTriggerClick);
      this.inputEl.removeEventListener("input", this._handleInput);
      this.inputEl.removeEventListener("keydown", this._handleKeydown);
      this.listEl.removeEventListener("mousedown", this._handleItemMousedown);
      this.listEl.removeEventListener("click", this._handleItemClick);
      if (this._shortcut) document.removeEventListener("keydown", this._handleGlobalShortcut);
      instances21.delete(this.triggerEl);
    }
  };
  autoInit("command-palette", CommandPalette);

  // packages/fokus-js/js/tree-view.js
  var instances22 = createInstanceRegistry();
  var TreeView = class {
    constructor(rootEl) {
      this.rootEl = rootEl;
      rootEl.setAttribute("role", "tree");
      this._setupItems();
      this._handleClick = this._handleClick.bind(this);
      this._handleKeydown = this._handleKeydown.bind(this);
      this._handleFocusIn = this._handleFocusIn.bind(this);
      rootEl.addEventListener("click", this._handleClick);
      rootEl.addEventListener("keydown", this._handleKeydown);
      rootEl.addEventListener("focusin", this._handleFocusIn);
      this._resetRovingTabindex();
      instances22.set(rootEl, this);
    }
    static getInstance(el) {
      return instances22.get(el);
    }
    _setupItems() {
      Array.from(this.rootEl.querySelectorAll("li")).forEach((li) => {
        var _a;
        li.setAttribute("role", "treeitem");
        const group = this._group(li);
        if (!group) return;
        group.setAttribute("role", "group");
        if (!li.hasAttribute("aria-expanded")) li.setAttribute("aria-expanded", "false");
        group.hidden = li.getAttribute("aria-expanded") !== "true";
        const label = (_a = li.querySelector(":scope > .fs-tree-label")) != null ? _a : li;
        if (!label.querySelector(":scope > .fs-tree-toggle")) {
          const toggle = document.createElement("span");
          toggle.className = "fs-tree-toggle";
          toggle.setAttribute("aria-hidden", "true");
          label.insertBefore(toggle, label.firstChild);
        }
      });
    }
    _group(item) {
      return item.querySelector(":scope > ul, :scope > ol");
    }
    _isExpanded(item) {
      return item.getAttribute("aria-expanded") === "true";
    }
    _isVisible(item) {
      let group = item.parentElement.closest('[role="group"]');
      while (group) {
        if (group.hidden) return false;
        group = group.parentElement.closest('[role="group"]');
      }
      return true;
    }
    _visibleItems() {
      return Array.from(this.rootEl.querySelectorAll('[role="treeitem"]')).filter((item) => this._isVisible(item));
    }
    _resetRovingTabindex() {
      var _a;
      const items = this._visibleItems();
      const current = (_a = items.find((item) => item.getAttribute("tabindex") === "0")) != null ? _a : items[0];
      items.forEach((item) => item.setAttribute("tabindex", item === current ? "0" : "-1"));
    }
    expand(item) {
      const group = this._group(item);
      if (!group || this._isExpanded(item)) return;
      item.setAttribute("aria-expanded", "true");
      group.hidden = false;
      this._resetRovingTabindex();
      this.rootEl.dispatchEvent(new CustomEvent("fs:tree:expanded", { bubbles: true, detail: { item } }));
    }
    collapse(item) {
      const group = this._group(item);
      if (!group || !this._isExpanded(item)) return;
      item.setAttribute("aria-expanded", "false");
      group.hidden = true;
      this._resetRovingTabindex();
      this.rootEl.dispatchEvent(new CustomEvent("fs:tree:collapsed", { bubbles: true, detail: { item } }));
    }
    toggle(item) {
      if (this._isExpanded(item)) {
        this.collapse(item);
      } else {
        this.expand(item);
      }
    }
    select(item) {
      var _a;
      this.rootEl.querySelectorAll('[aria-selected="true"]').forEach((el) => el.setAttribute("aria-selected", "false"));
      item.setAttribute("aria-selected", "true");
      this.rootEl.dispatchEvent(
        new CustomEvent("fs:tree:selected", { bubbles: true, detail: { value: (_a = item.dataset.value) != null ? _a : item.textContent.trim(), item } })
      );
    }
    _handleClick(event) {
      const item = event.target.closest('[role="treeitem"]');
      if (!item) return;
      if (this._group(item)) this.toggle(item);
      this.select(item);
      item.focus();
    }
    _handleFocusIn(event) {
      const item = event.target.closest('[role="treeitem"]');
      if (!item || !this._isVisible(item)) return;
      this._visibleItems().forEach((el) => el.setAttribute("tabindex", el === item ? "0" : "-1"));
    }
    _handleKeydown(event) {
      var _a, _b, _c, _d, _e, _f;
      const item = event.target.closest('[role="treeitem"]');
      if (!item) return;
      const items = this._visibleItems();
      const index = items.indexOf(item);
      const group = this._group(item);
      switch (event.key) {
        case "ArrowDown":
          event.preventDefault();
          (_a = items[index + 1]) == null ? void 0 : _a.focus();
          break;
        case "ArrowUp":
          event.preventDefault();
          (_b = items[index - 1]) == null ? void 0 : _b.focus();
          break;
        case "ArrowRight":
          event.preventDefault();
          if (group) {
            if (this._isExpanded(item)) (_c = items[index + 1]) == null ? void 0 : _c.focus();
            else this.expand(item);
          }
          break;
        case "ArrowLeft":
          event.preventDefault();
          if (group && this._isExpanded(item)) {
            this.collapse(item);
          } else {
            (_d = item.parentElement.closest('[role="treeitem"]')) == null ? void 0 : _d.focus();
          }
          break;
        case "Home":
          event.preventDefault();
          (_e = items[0]) == null ? void 0 : _e.focus();
          break;
        case "End":
          event.preventDefault();
          (_f = items[items.length - 1]) == null ? void 0 : _f.focus();
          break;
        case "Enter":
        case " ":
          event.preventDefault();
          if (group) this.toggle(item);
          this.select(item);
          break;
        default:
          return;
      }
    }
    dispose() {
      this.rootEl.removeEventListener("click", this._handleClick);
      this.rootEl.removeEventListener("keydown", this._handleKeydown);
      this.rootEl.removeEventListener("focusin", this._handleFocusIn);
      instances22.delete(this.rootEl);
    }
  };
  autoInit("tree-view", TreeView);

  // packages/fokus-js/js/range.js
  var instances23 = createInstanceRegistry();
  function computePercent(inputEl) {
    const min = Number(inputEl.min || 0);
    const max = Number(inputEl.max || 100);
    const value = Number(inputEl.value);
    if (max === min) return 0;
    return (value - min) / (max - min) * 100;
  }
  var RangeSlider = class {
    constructor(inputEl) {
      const targetSelector = inputEl.getAttribute("data-fs-target");
      const outputEl = targetSelector ? document.querySelector(targetSelector) : null;
      this.inputEl = inputEl;
      this.outputEl = outputEl;
      this._handleInput = this._handleInput.bind(this);
      inputEl.addEventListener("input", this._handleInput);
      this._render();
      instances23.set(inputEl, this);
    }
    static getInstance(el) {
      return instances23.get(el);
    }
    _handleInput() {
      this._render();
    }
    _render() {
      this.inputEl.style.setProperty("--fs-range-percent", `${computePercent(this.inputEl)}%`);
      if (this.outputEl) this.outputEl.textContent = this.inputEl.value;
    }
    dispose() {
      this.inputEl.removeEventListener("input", this._handleInput);
      instances23.delete(this.inputEl);
    }
  };
  autoInit("range", RangeSlider);

  // packages/fokus-js/js/file-upload-advanced.js
  var instances24 = createInstanceRegistry();
  var idCounter11 = 0;
  function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    const units = ["KB", "MB", "GB"];
    let value = bytes;
    let unitIndex = -1;
    do {
      value /= 1024;
      unitIndex += 1;
    } while (value >= 1024 && unitIndex < units.length - 1);
    return `${value.toFixed(1)} ${units[unitIndex]}`;
  }
  function fileExtension(name) {
    const dot = name.lastIndexOf(".");
    return dot === -1 ? "" : name.slice(dot + 1, dot + 5).toUpperCase();
  }
  var FileUploadAdvanced = class {
    constructor(rootEl) {
      const inputEl = rootEl.querySelector(".fs-file-input");
      if (!inputEl) {
        throw new Error("FokusStyles.FileUploadAdvanced: .fs-file-input n\xE3o encontrado.");
      }
      this.rootEl = rootEl;
      this.inputEl = inputEl;
      this.listEl = rootEl.querySelector(".fs-file-upload-list");
      if (!this.listEl) {
        this.listEl = document.createElement("ul");
        this.listEl.className = "fs-file-upload-list";
        rootEl.appendChild(this.listEl);
      }
      this._items = /* @__PURE__ */ new Map();
      this._handleChange = this._handleChange.bind(this);
      this._handleListClick = this._handleListClick.bind(this);
      inputEl.addEventListener("change", this._handleChange);
      this.listEl.addEventListener("click", this._handleListClick);
      instances24.set(rootEl, this);
    }
    static getInstance(el) {
      return instances24.get(el);
    }
    getFiles() {
      return Array.from(this._items.values()).map((item) => item.file);
    }
    setProgress(id, percent) {
      const item = this._items.get(id);
      if (!item) return;
      item.progress = Math.min(100, Math.max(0, percent));
      item.error = null;
      this._renderItem(id);
    }
    setError(id, message) {
      const item = this._items.get(id);
      if (!item) return;
      item.error = message;
      this._renderItem(id);
    }
    remove(id) {
      const item = this._items.get(id);
      if (!item) return;
      if (item.thumbUrl) URL.revokeObjectURL(item.thumbUrl);
      this._items.delete(id);
      const itemEl = this.listEl.querySelector(`[data-file-id="${id}"]`);
      itemEl == null ? void 0 : itemEl.remove();
      this.rootEl.dispatchEvent(
        new CustomEvent("fs:file-upload:removed", { bubbles: true, detail: { id, file: item.file } })
      );
    }
    _handleChange() {
      var _a;
      const files = Array.from((_a = this.inputEl.files) != null ? _a : []);
      if (files.length === 0) return;
      const added = [];
      for (const file of files) {
        idCounter11 += 1;
        const id = `file-${idCounter11}`;
        const thumbUrl = file.type.startsWith("image/") ? URL.createObjectURL(file) : null;
        this._items.set(id, { file, progress: 0, error: null, thumbUrl });
        added.push({ id, file });
        this._appendItem(id);
      }
      this.rootEl.dispatchEvent(new CustomEvent("fs:file-upload:added", { bubbles: true, detail: { items: added } }));
    }
    _handleListClick(event) {
      const removeBtn = event.target.closest("[data-file-upload-remove]");
      if (!removeBtn) return;
      const itemEl = removeBtn.closest("[data-file-id]");
      if (itemEl) this.remove(itemEl.getAttribute("data-file-id"));
    }
    _appendItem(id) {
      const item = this._items.get(id);
      const li = document.createElement("li");
      li.className = "fs-file-upload-item";
      li.setAttribute("data-file-id", id);
      this.listEl.appendChild(li);
      this._renderItem(id, item);
    }
    _renderItem(id, itemArg) {
      const item = itemArg != null ? itemArg : this._items.get(id);
      if (!item) return;
      const li = this.listEl.querySelector(`[data-file-id="${id}"]`);
      if (!li) return;
      const { file, progress, error, thumbUrl } = item;
      const thumb = thumbUrl ? `<img src="${thumbUrl}" alt="">` : escapeHtml(fileExtension(file.name) || "?");
      li.classList.toggle("is-error", Boolean(error));
      li.innerHTML = `
      <div class="fs-file-upload-thumb">${thumb}</div>
      <div class="fs-file-upload-info">
        <p class="fs-file-upload-name">${escapeHtml(file.name)}</p>
        <p class="fs-file-upload-size">${error ? escapeHtml(error) : formatBytes(file.size)}</p>
        <div class="fs-progress fs-progress-sm" role="progressbar" aria-valuenow="${progress}" aria-valuemin="0" aria-valuemax="100">
          <div class="fs-progress-bar${error ? " fs-progress-bar-danger" : ""}" style="--fs-progress-value: ${progress};"></div>
        </div>
      </div>
      <button type="button" class="fs-btn-close fs-file-upload-remove" data-file-upload-remove></button>
    `;
      li.querySelector('[role="progressbar"]').setAttribute("aria-label", `Progresso de ${file.name}`);
      li.querySelector("[data-file-upload-remove]").setAttribute("aria-label", `Remover ${file.name}`);
    }
    dispose() {
      this.inputEl.removeEventListener("change", this._handleChange);
      this.listEl.removeEventListener("click", this._handleListClick);
      for (const item of this._items.values()) {
        if (item.thumbUrl) URL.revokeObjectURL(item.thumbUrl);
      }
      this._items.clear();
      instances24.delete(this.rootEl);
    }
  };
  autoInit("file-upload-advanced", FileUploadAdvanced);

  // packages/fokus-js/js/theme.js
  var instances25 = createInstanceRegistry();
  var Theme = class _Theme {
    constructor(root = document.documentElement) {
      this.root = root;
      instances25.set(root, this);
    }
    static getInstance(root = document.documentElement) {
      return instances25.get(root);
    }
    static getOrCreateInstance(root = document.documentElement) {
      var _a;
      return (_a = this.getInstance(root)) != null ? _a : new _Theme(root);
    }
    static set(mode, root = document.documentElement) {
      return this.getOrCreateInstance(root).set(mode);
    }
    get() {
      var _a, _b;
      return (_b = (_a = this.root.getAttribute("data-fs-theme")) != null ? _a : this.root.getAttribute("data-theme")) != null ? _b : "light";
    }
    set(mode) {
      if (!["light", "dark", "auto"].includes(mode)) throw new TypeError("FokusStyles.Theme: modo inv\xE1lido.");
      this.root.setAttribute("data-fs-theme", mode);
      this.root.setAttribute("data-theme", mode === "dark" ? "dark" : "light");
      this.root.dispatchEvent(new CustomEvent("fs:theme:change", { bubbles: true, detail: { mode } }));
      return this;
    }
    dispose() {
      instances25.delete(this.root);
    }
  };

  // packages/fokus-js/js/navbar.js
  var instances26 = createInstanceRegistry();
  var Navbar = class _Navbar {
    constructor(navbarEl) {
      var _a;
      this.navbarEl = navbarEl;
      this.toggleEl = navbarEl.querySelector('[data-fs-toggle="navbar"]');
      const target = (_a = this.toggleEl) == null ? void 0 : _a.getAttribute("data-fs-target");
      this.menuEl = target ? document.querySelector(target) : navbarEl.querySelector(".fs-navbar-collapse");
      if (!this.toggleEl || !this.menuEl) return;
      this._toggle = () => this.toggle();
      this._escape = (event) => {
        if (event.key === "Escape" && this.isOpen()) this.hide();
      };
      this.toggleEl.addEventListener("click", this._toggle);
      this.menuEl.addEventListener("keydown", this._escape);
      instances26.set(navbarEl, this);
    }
    static getInstance(el) {
      return instances26.get(el);
    }
    static getOrCreateInstance(el) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _Navbar(el);
    }
    isOpen() {
      var _a;
      return (_a = this.menuEl) == null ? void 0 : _a.classList.contains("is-open");
    }
    show() {
      this.menuEl.classList.add("is-open");
      this.menuEl.hidden = false;
      this.toggleEl.setAttribute("aria-expanded", "true");
      this.navbarEl.dispatchEvent(new CustomEvent("fs:shown", { bubbles: true }));
    }
    hide() {
      this.menuEl.classList.remove("is-open");
      this.menuEl.hidden = true;
      this.toggleEl.setAttribute("aria-expanded", "false");
      this.toggleEl.focus();
      this.navbarEl.dispatchEvent(new CustomEvent("fs:hidden", { bubbles: true }));
    }
    toggle() {
      this.isOpen() ? this.hide() : this.show();
    }
    dispose() {
      var _a, _b;
      (_a = this.toggleEl) == null ? void 0 : _a.removeEventListener("click", this._toggle);
      (_b = this.menuEl) == null ? void 0 : _b.removeEventListener("keydown", this._escape);
      instances26.delete(this.navbarEl);
    }
  };
  autoInit("navbar", Navbar);

  // packages/fokus-js/js/scrollspy.js
  var instances27 = createInstanceRegistry();
  var Scrollspy = class _Scrollspy {
    constructor(navEl) {
      this.navEl = navEl;
      this.links = [...navEl.querySelectorAll('a[href^="#"]')];
      this.sections = this.links.map((link) => document.getElementById(link.getAttribute("href").slice(1))).filter(Boolean);
      this.observer = typeof IntersectionObserver === "undefined" ? null : new IntersectionObserver((entries) => entries.forEach((entry) => entry.isIntersecting && this.activate(entry.target)), { rootMargin: "-20% 0px -65%" });
      this.sections.forEach((section) => {
        var _a;
        return (_a = this.observer) == null ? void 0 : _a.observe(section);
      });
      this._onScroll = () => {
        if (this.observer) return;
        const current = this.sections.filter((section) => section.getBoundingClientRect().top <= window.innerHeight * 0.35).at(-1);
        if (current) this.activate(current);
      };
      if (!this.observer) window.addEventListener("scroll", this._onScroll, { passive: true });
      instances27.set(navEl, this);
    }
    static getInstance(el) {
      return instances27.get(el);
    }
    static getOrCreateInstance(el) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _Scrollspy(el);
    }
    activate(section) {
      this.links.forEach((link) => link.classList.toggle("is-active", link.getAttribute("href") === `#${section.id}`));
      this.navEl.dispatchEvent(new CustomEvent("fs:scrollspy:activate", { bubbles: true, detail: { section } }));
    }
    dispose() {
      var _a;
      (_a = this.observer) == null ? void 0 : _a.disconnect();
      window.removeEventListener("scroll", this._onScroll);
      instances27.delete(this.navEl);
    }
  };
  autoInit("scrollspy", Scrollspy);

  // packages/fokus-js/js/form-validation.js
  var instances28 = createInstanceRegistry();
  var FormValidation = class _FormValidation {
    constructor(form) {
      this.form = form;
      this._submit = (event) => this.validate(event);
      form.addEventListener("submit", this._submit);
      instances28.set(form, this);
    }
    static getInstance(el) {
      return instances28.get(el);
    }
    static getOrCreateInstance(el) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _FormValidation(el);
    }
    validate(event) {
      const valid = this.form.checkValidity();
      this.form.classList.add("fs-was-validated");
      Array.from(this.form.elements).filter((field) => field.willValidate).forEach((field) => field.setAttribute("aria-invalid", String(!field.checkValidity())));
      if (!valid) {
        event.preventDefault();
        event.stopPropagation();
      }
      this.form.dispatchEvent(new CustomEvent("fs:form:validated", { bubbles: true, detail: { valid } }));
      return valid;
    }
    dispose() {
      this.form.removeEventListener("submit", this._submit);
      instances28.delete(this.form);
    }
  };
  autoInit("form-validation", FormValidation);

  // packages/fokus-js/js/spinner.js
  var instances29 = createInstanceRegistry();
  var Spinner = class _Spinner {
    constructor(element) {
      this.element = element;
      this._setLoading(element.hasAttribute("data-fs-loading") && element.getAttribute("data-fs-loading") !== "false");
      instances29.set(element, this);
    }
    static getInstance(el) {
      return instances29.get(el);
    }
    static getOrCreateInstance(el) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _Spinner(el);
    }
    _setLoading(value) {
      this.loading = value;
      this.element.setAttribute("aria-busy", String(value));
      this.element.toggleAttribute("data-fs-loading", value);
    }
    start() {
      this._setLoading(true);
      this.element.dispatchEvent(new CustomEvent("fs:loading:start", { bubbles: true }));
    }
    stop() {
      this._setLoading(false);
      this.element.dispatchEvent(new CustomEvent("fs:loading:stop", { bubbles: true }));
    }
    toggle() {
      this.loading ? this.stop() : this.start();
    }
    dispose() {
      instances29.delete(this.element);
    }
  };
  autoInit("spinner", Spinner);

  // packages/fokus-js/js/pagination.js
  var instances30 = createInstanceRegistry();
  var Pagination = class _Pagination {
    constructor(element) {
      this.element = element;
      this.total = Math.max(1, this._normalizePage(element.getAttribute("data-fs-pages"), element.querySelectorAll("[data-fs-page]").length || 1));
      this.current = Math.min(this.total, this._normalizePage(element.getAttribute("data-fs-page"), 1));
      this._click = (event) => {
        const button = event.target.closest("[data-fs-page]");
        if (!button || !this.element.contains(button)) return;
        event.preventDefault();
        if (button.disabled || button.getAttribute("aria-disabled") === "true" || button.closest(".is-disabled")) return;
        this.goTo(button.getAttribute("data-fs-page"));
      };
      element.addEventListener("click", this._click);
      instances30.set(element, this);
      this._render();
    }
    static getInstance(el) {
      return instances30.get(el);
    }
    static getOrCreateInstance(el) {
      var _a;
      return (_a = this.getInstance(el)) != null ? _a : new _Pagination(el);
    }
    goTo(page) {
      const next = this._normalizePage(page, this.current);
      const bounded = Math.max(1, Math.min(this.total, next));
      if (bounded === this.current) return;
      this.current = bounded;
      this.element.setAttribute("data-fs-page", String(bounded));
      this._render();
      this.element.dispatchEvent(new CustomEvent("fs:pagination:change", { bubbles: true, detail: { page: bounded, total: this.total } }));
    }
    _render() {
      this.element.querySelectorAll("[data-fs-page]").forEach((item) => {
        var _a;
        const active = Number(item.getAttribute("data-fs-page")) === this.current;
        item.classList.toggle("is-active", active);
        (_a = item.closest(".fs-page-item")) == null ? void 0 : _a.classList.toggle("is-active", active);
        if (active) item.setAttribute("aria-current", "page");
        else item.removeAttribute("aria-current");
      });
    }
    _normalizePage(value, fallback) {
      const parsed = Number(value);
      return Number.isFinite(parsed) && parsed >= 1 ? Math.floor(parsed) : fallback;
    }
    dispose() {
      this.element.removeEventListener("click", this._click);
      instances30.delete(this.element);
    }
  };
  autoInit("pagination", Pagination);
  return __toCommonJS(fokus_exports);
})();
//# sourceMappingURL=fokus.js.map
