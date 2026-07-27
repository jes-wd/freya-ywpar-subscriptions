(function () {
  'use strict';

  var config = window.freyaMyAccountReferral || {};
  var linkInput = document.getElementById('freya-my-account-ref-link');
  var copyBtn = document.getElementById('freya-my-account-copy-btn');
  var copiedMsg = document.getElementById('freya-my-account-copied-msg');

  if (!linkInput || !copyBtn) {
    return;
  }

  var copiedTimer;

  function showCopiedMsg() {
    if (!copiedMsg) {
      return;
    }
    copiedMsg.hidden = false;
    copiedMsg.classList.add('is-visible');
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(function () {
      copiedMsg.classList.remove('is-visible');
      copiedMsg.hidden = true;
    }, 2600);
  }

  function afterCopy() {
    showCopiedMsg();
  }

  function legacyCopy() {
    linkInput.removeAttribute('readonly');
    linkInput.select();
    linkInput.setSelectionRange(0, 99999);
    try {
      document.execCommand('copy');
    } catch (e) {}
    linkInput.setAttribute('readonly', 'readonly');
    afterCopy();
  }

  function doCopy() {
    var text = linkInput.value;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(afterCopy, legacyCopy);
    } else {
      legacyCopy();
    }
  }

  copyBtn.addEventListener('click', doCopy);
  linkInput.addEventListener('click', function () {
    linkInput.select();
  });
})();
