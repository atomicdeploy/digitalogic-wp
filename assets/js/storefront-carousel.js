(function() {
	'use strict';

	var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	document.querySelectorAll('[data-dgl-story-carousel]').forEach(function(carousel) {
		var slides = Array.from(carousel.querySelectorAll('[data-dgl-story-slide]'));
		var dots = Array.from(carousel.querySelectorAll('[data-dgl-story-dot]'));
		var currentOutput = carousel.querySelector('[data-dgl-story-current]');
		var index = 0;
		var timer = null;

		function show(nextIndex, announce) {
			index = (nextIndex + slides.length) % slides.length;
			slides.forEach(function(slide, slideIndex) {
				var active = slideIndex === index;
				slide.hidden = !active;
				slide.classList.toggle('is-active', active);
				slide.setAttribute('aria-hidden', active ? 'false' : 'true');
			});
			dots.forEach(function(dot, dotIndex) {
				dot.setAttribute('aria-selected', dotIndex === index ? 'true' : 'false');
			});
			if (currentOutput) currentOutput.textContent = String(index + 1).padStart(2, '0');
			if (announce) carousel.querySelector('.dgl-story-slides').setAttribute('aria-live', 'polite');
		}

		function stop() {
			window.clearInterval(timer);
			timer = null;
		}

		function start() {
			if (reducedMotion || slides.length < 2 || timer) return;
			timer = window.setInterval(function() { show(index + 1, false); }, 7000);
		}

		carousel.querySelector('[data-dgl-story-prev]').addEventListener('click', function() {
			show(index - 1, true);
			stop();
		});
		carousel.querySelector('[data-dgl-story-next]').addEventListener('click', function() {
			show(index + 1, true);
			stop();
		});
		dots.forEach(function(dot) {
			dot.addEventListener('click', function() {
				show(parseInt(dot.getAttribute('data-dgl-story-dot'), 10), true);
				stop();
			});
		});
		carousel.addEventListener('mouseenter', stop);
		carousel.addEventListener('mouseleave', start);
		carousel.addEventListener('focusin', stop);
		carousel.addEventListener('focusout', start);
		carousel.addEventListener('keydown', function(event) {
			if (event.key === 'ArrowLeft') show(index + 1, true);
			if (event.key === 'ArrowRight') show(index - 1, true);
		});
		show(0, false);
		start();
	});

	document.querySelectorAll('.dgl-section').forEach(function(section) {
		var rail = section.querySelector('[data-dgl-rail]');
		var previous = section.querySelector('[data-dgl-rail-prev]');
		var next = section.querySelector('[data-dgl-rail-next]');
		if (!rail || !previous || !next) return;

		function move(forward) {
			var amount = Math.max(260, rail.clientWidth * 0.82);
			var rtl = window.getComputedStyle(rail).direction === 'rtl';
			var sign = forward ? 1 : -1;
			rail.scrollBy({
				left: sign * amount * (rtl ? -1 : 1),
				behavior: reducedMotion ? 'auto' : 'smooth'
			});
		}

		previous.addEventListener('click', function() { move(false); });
		next.addEventListener('click', function() { move(true); });
	});
})();
