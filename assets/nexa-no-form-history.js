(function(){
  'use strict';
  var credentialTokens = /^(?:username|current-password|new-password|one-time-code|webauthn)$/i;
  function protectField(el){
    if(!el || el.nodeType!==1 || el.hasAttribute('data-browser-autofill-allow')) return;
    var tag=(el.tagName||'').toLowerCase();
    if(tag!=='input' && tag!=='textarea') return;
    if(tag==='input'){
      var type=(el.getAttribute('type')||'text').toLowerCase();
      if(['hidden','password','checkbox','radio','file','button','submit','reset','image','color','range'].indexOf(type)!==-1) return;
    }
    var existing=(el.getAttribute('autocomplete')||'').trim();
    if(credentialTokens.test(existing)) return;
    el.setAttribute('autocomplete','off');
    el.setAttribute('data-lpignore','true');
    el.setAttribute('data-1p-ignore','true');
  }
  function protectForm(form){
    if(!form || form.nodeType!==1 || form.hasAttribute('data-browser-autofill-allow')) return;
    form.setAttribute('autocomplete','off');
    form.querySelectorAll('input,textarea').forEach(protectField);
  }
  function scan(root){
    if(!root || root.nodeType!==1 && root.nodeType!==9) return;
    if(root.matches && root.matches('form')) protectForm(root);
    if(root.matches && root.matches('input,textarea')) protectField(root);
    root.querySelectorAll && root.querySelectorAll('form').forEach(protectForm);
    root.querySelectorAll && root.querySelectorAll('input,textarea').forEach(protectField);
  }
  function start(){
    scan(document);
    // Protege tambem campos ativados apos a renderizacao inicial (pesquisas e modais).
    document.addEventListener('focusin', function(ev){
      var target=ev.target;
      if(target && target.matches && target.matches('input,textarea')) protectField(target);
    }, true);
    if(!window.MutationObserver) return;
    var obs=new MutationObserver(function(list){
      list.forEach(function(m){ m.addedNodes.forEach(function(n){ if(n.nodeType===1) scan(n); }); });
    });
    obs.observe(document.documentElement,{childList:true,subtree:true});
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',start,{once:true}); else start();
})();
