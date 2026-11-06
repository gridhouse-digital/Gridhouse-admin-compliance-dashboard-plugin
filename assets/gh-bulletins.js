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

  function bulletins() {
    return payload.bulletins || {}
  }

  document.querySelectorAll('[data-gh-ticker]').forEach(function (ticker) {
    if (reducedMotion()) {
      ticker.setAttribute('data-motion', 'reduce')
      return
    }
    var track = ticker.querySelector('.gh-ticker__track')
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
      pause.textContent = paused ? (pause.getAttribute('data-label-play') || 'Play') : (pause.getAttribute('data-label-pause') || 'Pause')
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

  function openDrawer(id, opener) {
    var item = bulletins()[id]
    if (!item || !drawer) return false
    lastFocus = opener || document.activeElement
    var title = drawer.querySelector('[data-gh-drawer-title]')
    var meta = drawer.querySelector('[data-gh-drawer-meta]')
    var body = drawer.querySelector('[data-gh-drawer-body]')
    var full = drawer.querySelector('[data-gh-drawer-full]')
    var copy = drawer.querySelector('[data-gh-copy]')
    var sourceWrap = drawer.querySelector('[data-gh-drawer-source-wrap]')
    title.textContent = item.headline || ''
    meta.textContent = (item.date_label || '') + (item.audience_label ? ' · ' + item.audience_label : '')
    body.textContent = ''
    ;(item.paragraphs || []).forEach(function (paragraph) {
      var p = document.createElement('p')
      p.className = 'gh-odp-copy'
      p.textContent = paragraph
      body.appendChild(p)
    })
    if (full) full.setAttribute('href', item.url || '#')
    if (copy) copy.setAttribute('data-url', item.url || '')
    sourceWrap.textContent = ''
    sourceWrap.hidden = true
    if (item.source_url && /^https?:\/\//i.test(item.source_url)) {
      var link = document.createElement('a')
      link.href = item.source_url
      link.target = '_blank'
      link.rel = 'noopener noreferrer'
      link.textContent = 'Read source'
      sourceWrap.appendChild(link)
      sourceWrap.hidden = false
    }
    drawer.hidden = false
    document.documentElement.classList.add('gh-ui-drawer-open')
    if (window.ghcaAcdOverlay) window.ghcaAcdOverlay.open(drawer, lastFocus, closeDrawer)
    var closeBtn = drawer.querySelector('.gh-drawer__bar [data-gh-drawer-close]')
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
    var panel = drawer.querySelector('.gh-drawer__panel')
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
