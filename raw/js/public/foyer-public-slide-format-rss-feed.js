(function($){
	function getSlideDuration($slide){
		var duration = parseFloat($slide.data('foyer-slide-duration'));
		if (!(duration > 0)) {
			duration = 5;
		}
		return duration;
	}

	function resetImage($img){
		$img.css({
			transition: '',
			transform: 'scale(1)'
		});
	}

	function queueFrame(callback){
		if (window.requestAnimationFrame) {
			requestAnimationFrame(function(){
				requestAnimationFrame(callback);
			});
		}
		else {
			setTimeout(callback, 50);
		}
	}

	function startBackgroundZoom($background, duration){
		var $img = $background.find('figure img').first();
		if (!$img.length) {
			return;
		}

		if ($img[0] && !$img[0].complete) {
			$img.one('load', function(){
				startBackgroundZoom($background, duration);
			});
			return;
		}

		resetImage($img);

		queueFrame(function(){
			$img.css({
				transition: 'transform ' + duration + 's ease-in-out',
				transform: 'scale(1.15)'
			});
		});
	}

	function resetSlideZoom($slide){
		$slide.find('.foyer-slide-background-zoom figure img').each(function(){
			resetImage($(this));
		});
	}

	function startSlideZoom($slide){
		var $backgrounds = $slide.find('.foyer-slide-background-zoom');
		if (!$backgrounds.length) {
			return;
		}

		var duration = getSlideDuration($slide);
		$backgrounds.each(function(){
			startBackgroundZoom($(this), duration);
		});
	}

	$(document)
		.on('slide:becoming-next', '.foyer-slide', function(){
			resetSlideZoom($(this));
		})
		.on('slide:becoming-active', '.foyer-slide', function(){
			startSlideZoom($(this));
		});

	$(function(){
		var $activeSlides = $('.foyer-slide.active');
		var $targetSlides = $activeSlides.length ? $activeSlides : $('.foyer-slide');
		$targetSlides.each(function(){
			startSlideZoom($(this));
		});
	});
})(jQuery);
