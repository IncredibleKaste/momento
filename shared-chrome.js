(() => {
 function sync(){
  const theme=document.documentElement.dataset.theme==='dark'?'dark':'light';
  document.querySelectorAll('.site-footer a,.top [data-destination]').forEach(link=>{const url=new URL(link.href);url.searchParams.set('theme',theme);link.href=url.href});
  document.querySelectorAll('.theme-toggle').forEach(button=>{button.setAttribute('aria-label',`Switch to ${theme==='dark'?'light':'dark'} theme`);button.title=button.getAttribute('aria-label')});
 }
 sync();new MutationObserver(sync).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
 document.querySelectorAll('[data-copyright-year]').forEach(node=>node.textContent=new Date().getFullYear());
 const footer=document.querySelector('.feed-footer');
 if(footer&&'IntersectionObserver' in window){
  const observer=new IntersectionObserver(entries=>{entries.forEach(entry=>footer.classList.toggle('is-waiting',!entry.isIntersecting))},{threshold:0});
  observer.observe(footer);
 }
})();
