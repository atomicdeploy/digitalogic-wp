(function() {
	'use strict';

	var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	document.querySelectorAll('[data-dgl-story-carousel]').forEach(function(carousel) {
		var slides = Array.from(carousel.querySelectorAll('[data-dgl-story-slide]'));
		var dots = Array.from(carousel.querySelectorAll('[data-dgl-story-dot]'));
		var slideRegion = carousel.querySelector('.dgl-story-slides');
		var currentOutput = carousel.querySelector('[data-dgl-story-current]');
		var autoplayToggle = carousel.querySelector('[data-dgl-story-autoplay]');
		var autoplayLabel = carousel.querySelector('[data-dgl-story-autoplay-label]');
		var autoplayIcon = carousel.querySelector('[data-dgl-story-autoplay-icon]');
		var autoplayStatus = carousel.querySelector('[data-dgl-story-autoplay-status]');
		var index = 0;
		var timer = null;
		var pointerInside = false;
		var focusInside = false;
		var autoplayUnavailable = reducedMotion || slides.length < 2;
		var userPaused = autoplayUnavailable;

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
			if (slideRegion) slideRegion.setAttribute('aria-live', announce ? 'polite' : 'off');
		}

		function stop() {
			window.clearInterval(timer);
			timer = null;
		}

		function start() {
			if (autoplayUnavailable || userPaused || pointerInside || focusInside || timer) return;
			timer = window.setInterval(function() { show(index + 1, false); }, 7000);
		}

		function updateAutoplayControl() {
			if (!autoplayToggle) return;

			autoplayToggle.disabled = autoplayUnavailable;
			autoplayToggle.setAttribute('aria-pressed', userPaused ? 'true' : 'false');

			if (autoplayUnavailable) {
				autoplayToggle.setAttribute('aria-label', reducedMotion ? 'پخش خودکار به‌خاطر تنظیم کاهش حرکت خاموش است' : 'پخش خودکار برای یک اسلاید در دسترس نیست');
				if (autoplayLabel) autoplayLabel.textContent = 'پخش خودکار خاموش';
				if (autoplayIcon) autoplayIcon.textContent = '■';
				return;
			}

			autoplayToggle.setAttribute('aria-label', userPaused ? 'ادامه پخش خودکار اسلایدها' : 'توقف پخش خودکار اسلایدها');
			if (autoplayLabel) autoplayLabel.textContent = userPaused ? 'ادامه پخش' : 'توقف پخش';
			if (autoplayIcon) autoplayIcon.textContent = userPaused ? '▶' : 'Ⅱ';
		}

		function announceAutoplayState() {
			if (!autoplayStatus) return;
			if (userPaused) {
				autoplayStatus.textContent = 'پخش خودکار اسلایدها متوقف شد.';
			} else if (pointerInside || focusInside) {
				autoplayStatus.textContent = 'پخش خودکار فعال شد و بعد از خروج نشانگر یا فوکوس ادامه پیدا می‌کند.';
			} else {
				autoplayStatus.textContent = 'پخش خودکار اسلایدها ادامه پیدا کرد.';
			}
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
		if (autoplayToggle) {
			autoplayToggle.addEventListener('click', function() {
				if (autoplayUnavailable) return;
				userPaused = !userPaused;
				if (userPaused) {
					stop();
				} else {
					start();
				}
				updateAutoplayControl();
				announceAutoplayState();
			});
		}
		carousel.addEventListener('mouseenter', function() {
			pointerInside = true;
			stop();
		});
		carousel.addEventListener('mouseleave', function() {
			pointerInside = false;
			start();
		});
		carousel.addEventListener('focusin', function() {
			focusInside = true;
			stop();
		});
		carousel.addEventListener('focusout', function(event) {
			if (event.relatedTarget && carousel.contains(event.relatedTarget)) return;
			focusInside = false;
			start();
		});
		carousel.addEventListener('keydown', function(event) {
			if (event.key === 'ArrowLeft') show(index + 1, true);
			if (event.key === 'ArrowRight') show(index - 1, true);
		});
		updateAutoplayControl();
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
