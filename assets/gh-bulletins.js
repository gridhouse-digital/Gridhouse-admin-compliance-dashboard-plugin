(function () {
  'use strict'

  var drawer = document.getElementById('gh-odp-drawer')
  var payloadNode = document.getElementById('gh-odp-data')
  var lastFocus = null
  var payload = {}

  if (payloadNode) {
    try {
      payload = JSON.parse(payloadNode.textContent || '{}')
    } catch (error) {
      payload = {}
    }
  }

  function reducedMotion() {
    return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches
  }

  var currentId = ''

  function bulletins() {
    return payload.bulletins || {}
  }

  function order() {
    return Array.isArray(payload.order) ? payload.order : Object.keys(bulletins())
  }

  // Full-bleed sizing subtracts the scrollbar so 100vw never causes a horizontal scroll.
  function setScrollbarWidth() {
    var width = window.innerWidth - document.documentElement.clientWidth
    document.documentElement.style.setProperty('--ghca-ui-sbw', Math.max(0, width) + 'px')
  }
  setScrollbarWidth()
  window.addEventListener('resize', setScrollbarWidth)

  document.querySelectorAll('[data-gh-ticker]').forEach(function (ticker) {
    if (reducedMotion()) {
      ticker.setAttribute('data-motion', 'reduce')
      return
    }
    var track = ticker.querySelector('.gh-ticker-track')
    var items = track ? track.children.length : 0
    if (!track || items < 2) return
    Array.prototype.slice.call(track.children).forEach(function (item) {
      var copy = item.cloneNode(true)
      copy.setAttribute('aria-hidden', 'true')
      copy.setAttribute('inert', '')
      Array.prototype.forEach.call(copy.querySelectorAll('a, button, input, textarea, select'), function (node) {
        node.setAttribute('tabindex', '-1')
      })
      track.appendChild(copy)
    })
    ticker.classList.add('is-animated')
  })

  document.addEventListener('click', function (event) {
    var pause = event.target.closest ? event.target.closest('[data-gh-ticker-pause]') : null
    if (pause) {
      var ticker = pause.closest('[data-gh-ticker]')
      var paused = ticker.classList.toggle('is-paused')
      pause.setAttribute('aria-pressed', paused ? 'true' : 'false')
      pause.setAttribute('aria-label', paused ? (pause.getAttribute('data-label-play') || 'Play') : (pause.getAttribute('data-label-pause') || 'Pause'))
      var glyph = pause.querySelector('.gh-i')
      if (glyph) {
        glyph.classList.toggle('i-pause', !paused)
        glyph.classList.toggle('i-play', paused)
      }
      return
    }
    var step = event.target.closest ? event.target.closest('[data-gh-drawer-step]') : null
    if (step && drawer && !drawer.hidden) {
      var ids = order()
      var next = ids[ids.indexOf(currentId) + parseInt(step.getAttribute('data-gh-drawer-step'), 10)]
      if (next) openDrawer(next, lastFocus, step)
      return
    }
    var opener = event.target.closest ? event.target.closest('[data-gh-open-bulletin]') : null
    if (opener && drawer) {
      if (openDrawer(opener.getAttribute('data-gh-open-bulletin'), opener)) event.preventDefault()
      return
    }
    if (event.target.closest && event.target.closest('[data-gh-drawer-close]')) {
      closeDrawer()
      return
    }
    var copy = event.target.closest ? event.target.closest('[data-gh-copy]') : null
    if (copy) copyLink(copy)
  })

  function openDrawer(id, opener, keepFocusOn) {
    var item = bulletins()[id]
    if (!item || !drawer) return false
    var reopening = !drawer.hidden
    if (!reopening) lastFocus = opener || document.activeElement
    currentId = id
    var title = drawer.querySelector('[data-gh-drawer-title]')
    var meta = drawer.querySelector('[data-gh-drawer-meta]')
    var body = drawer.querySelector('[data-gh-drawer-body]')
    var full = drawer.querySelector('[data-gh-drawer-full]')
    var pos = drawer.querySelector('[data-gh-drawer-pos]')
    var sourceWrap = drawer.querySelector('[data-gh-drawer-source-wrap]')
    var source = drawer.querySelector('[data-gh-drawer-source]')
    var host = drawer.querySelector('[data-gh-drawer-source-host]')
    title.textContent = item.headline || ''
    meta.textContent = item.date_long || item.date_label || ''
    meta.setAttribute('datetime', item.published_at || '')
    body.textContent = ''
    ;(item.paragraphs || []).forEach(function (paragraph) {
      var p = document.createElement('p')
      p.textContent = paragraph
      body.appendChild(p)
    })
    if (full) full.setAttribute('href', item.url || '#')
    var ids = order()
    var index = ids.indexOf(id)
    if (pos) pos.textContent = ids.length > 1 && index > -1 ? (index + 1) + ' of ' + ids.length : ''
    Array.prototype.forEach.call(drawer.querySelectorAll('[data-gh-drawer-step]'), function (button) {
      var target = index + parseInt(button.getAttribute('data-gh-drawer-step'), 10)
      button.disabled = index < 0 || target < 0 || target >= ids.length
    })
    sourceWrap.hidden = true
    if (source && item.source_url && /^https?:\/\//i.test(item.source_url)) {
      source.setAttribute('href', item.source_url)
      if (host) host.textContent = item.source_host ? 'Opens ' + item.source_host + ' in a new tab' : ''
      sourceWrap.hidden = false
    }
    var panel = drawer.querySelector('.gh-drawer')
    if (panel) panel.scrollTop = 0
    var bd = drawer.querySelector('.gh-bul-bd')
    if (bd) bd.scrollTop = 0
    if (reopening) {
      if (keepFocusOn && keepFocusOn.disabled) {
        var closeNow = drawer.querySelector('[data-gh-drawer-x]')
        if (closeNow) closeNow.focus()
      }
      return true
    }
    drawer.hidden = false
    document.documentElement.classList.add('gh-ui-drawer-open')
    if (window.ghcaAcdOverlay) window.ghcaAcdOverlay.open(drawer, lastFocus, closeDrawer)
    var closeBtn = drawer.querySelector('[data-gh-drawer-x]')
    if (closeBtn) closeBtn.focus()
    return true
  }

  function closeDrawer() {
    if (!drawer || drawer.hidden) return
    drawer.hidden = true
    document.documentElement.classList.remove('gh-ui-drawer-open')
    if (window.ghcaAcdOverlay) window.ghcaAcdOverlay.close(drawer)
    if (lastFocus && lastFocus.focus) lastFocus.focus()
    lastFocus = null
    currentId = ''
  }

  function focusable(panel) {
    return Array.prototype.slice.call(panel.querySelectorAll('a[href], button:not([disabled]), input, textarea, select, [tabindex]:not([tabindex="-1"])'))
      .filter(function (node) { return !node.closest('[hidden]') })
  }

  document.addEventListener('keydown', function (event) {
    if (!drawer || drawer.hidden) return
    if (window.ghcaAcdOverlay && !window.ghcaAcdOverlay.isTop(drawer)) return
    if (event.key === 'Escape') {
      event.preventDefault()
      closeDrawer()
      return
    }
    if (event.key !== 'Tab') return
    var panel = drawer.querySelector('.gh-drawer')
    var nodes = focusable(panel)
    if (!nodes.length) {
      event.preventDefault()
      panel.focus()
      return
    }
    var first = nodes[0]
    var last = nodes[nodes.length - 1]
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault()
      last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault()
      first.focus()
    }
  })

  function copyLink(button) {
    var url = button.getAttribute('data-url') || ''
    var status = button.parentNode ? button.parentNode.querySelector('[data-gh-copy-status]') : null
    var done = function () {
      if (status) status.textContent = 'Link copied'
      button.classList.add('is-copied')
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done, function () { fallbackCopy(url, done) })
      return
    }
    fallbackCopy(url, done)
  }

  function fallbackCopy(url, done) {
    var field = document.createElement('textarea')
    field.value = url
    field.setAttribute('readonly', '')
    document.body.appendChild(field)
    field.select()
    try { document.execCommand('copy') } catch (error) {}
    document.body.removeChild(field)
    done()
  }
})()
