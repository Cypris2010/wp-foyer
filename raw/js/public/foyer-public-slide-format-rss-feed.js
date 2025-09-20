(function($){
	function getSlideDuration($slide){
		var duration = parseFloat($slide.data('foyer-slide-duration'));
		if (!(duration > 0)) {
			duration = 5;
		}
		return duration;
	}

	function resetZoom($slide){
		$slide.find('.foyer-slide-rss-background figure img').css({
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

	function startZoom($slide){
		var $img = $slide.find('.foyer-slide-rss-background figure img').first();
		if (!$img.length) {
			return;
		}

		if ($img[0] && !$img[0].complete) {
			$img.one('load', function(){
				startZoom($slide);
			});
			return;
		}

		resetZoom($slide);

		var duration = getSlideDuration($slide);
		queueFrame(function(){
			$img.css({
				transition: 'transform ' + duration + 's ease-in-out',
				transform: 'scale(1.15)'
			});
		});
	}

	$(document)
		.on('slide:becoming-next', '.foyer-slide.foyer-slide-rss-feed', function(){
			resetZoom($(this));
		})
		.on('slide:becoming-active', '.foyer-slide.foyer-slide-rss-feed', function(){
			startZoom($(this));
		});

	$(function(){
		$('.foyer-slide.foyer-slide-rss-feed.active').each(function(){
			startZoom($(this));
		});
	});
})(jQuery);
