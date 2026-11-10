document.addEventListener('DOMContentLoaded',()=>{
	document.querySelectorAll('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!confirm(el.dataset.confirm))e.preventDefault()}));
	const toggle=document.querySelector('[data-app-nav-toggle]');
	const sidebar=document.getElementById('app-navigation');
	const backdrop=document.querySelector('[data-app-nav-backdrop]');
	if(!toggle||!sidebar||!backdrop)return;
	const closeNavigation=()=>{
		sidebar.classList.remove('is-open');
		document.body.classList.remove('app-nav-open');
		backdrop.hidden=true;
		toggle.setAttribute('aria-expanded','false');
		toggle.setAttribute('aria-label','Open navigation');
	};
	const openNavigation=()=>{
		sidebar.classList.add('is-open');
		document.body.classList.add('app-nav-open');
		backdrop.hidden=false;
		toggle.setAttribute('aria-expanded','true');
		toggle.setAttribute('aria-label','Close navigation');
	};
	toggle.addEventListener('click',()=>sidebar.classList.contains('is-open')?closeNavigation():openNavigation());
	backdrop.addEventListener('click',closeNavigation);
	sidebar.addEventListener('click',event=>{if(event.target.closest('a'))closeNavigation();});
	document.addEventListener('keydown',event=>{if(event.key==='Escape'&&sidebar.classList.contains('is-open')){closeNavigation();toggle.focus();}});
	const closeAtWidth=document.body.classList.contains('maintenance-shell')?761:901;
	window.addEventListener('resize',()=>{if(window.innerWidth>=closeAtWidth&&sidebar.classList.contains('is-open'))closeNavigation();});
});
