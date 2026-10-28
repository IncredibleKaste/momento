(() => {
 const stage=document.querySelector('.moments');
 const slides=[...stage.querySelectorAll('.moment')];
 const control=document.getElementById('moment-play');
 const reduced=matchMedia('(prefers-reduced-motion: reduce)');
 let current=0,paused=false,ready=false,timer,remaining=5200,due=0;
 const moves=new Map();
 function drift(slide,index){
  moves.get(slide)?.cancel();
  const image=slide.querySelector('img');
  const direction=index%2?1:-1;
  moves.set(slide,image.animate([
   {transform:`scale(1.065) translate(${direction*1.2}%, .6%)`},
   {transform:`scale(1.015) translate(${-direction*.4}%, -.3%)`}
  ],{duration:7600,easing:'linear',fill:'forwards'}));
 }
 function schedule(){clearTimeout(timer);due=performance.now()+remaining;timer=setTimeout(advance,remaining)}
 function advance(){
  const old=slides[current];
  slides.forEach(slide=>slide.classList.remove('is-previous'));
  old.classList.replace('is-current','is-previous');old.setAttribute('aria-hidden','true');
  current=(current+1)%slides.length;
  const next=slides[current];next.classList.add('is-current');next.setAttribute('aria-hidden','false');
  drift(next,current);
  next.animate([{opacity:0},{opacity:1}],{duration:1600,easing:'cubic-bezier(.4,0,.2,1)'});
  remaining=5200;schedule();
 }
 function sync(){
  const running=ready&&!paused&&!reduced.matches&&!document.hidden;
  if(!running&&timer){remaining=Math.max(0,due-performance.now());clearTimeout(timer);timer=null}
  stage.getAnimations({subtree:true}).forEach(animation=>running?animation.play():animation.pause());
  if(running)schedule();
  control.disabled=reduced.matches;control.setAttribute('aria-pressed',String(paused));
  control.textContent=reduced.matches?'Still preview':paused?'Play motion':'Pause motion';
 }
 control.addEventListener('click',()=>{paused=!paused;sync()});
 document.addEventListener('visibilitychange',sync);
 reduced.addEventListener('change',()=>{
  if(reduced.matches){stage.getAnimations({subtree:true}).forEach(a=>a.cancel());slides.forEach(s=>s.classList.remove('is-previous'))}
  else drift(slides[current],current);
  sync();
 });
 // Decode every local photograph before the first dissolve to avoid blank flashes.
 Promise.allSettled(slides.map(slide=>slide.querySelector('img').decode())).then(()=>{
  ready=true;if(!reduced.matches)drift(slides[current],current);sync();
 });
 sync();
})();
