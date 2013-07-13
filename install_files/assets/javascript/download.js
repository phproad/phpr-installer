var Phpr_Downloader = (function(dl, $){

	dl.url = null;
	dl.elBar = null;
	dl.elMsg = null;
	dl.eventChain = [];

	dl.totalProgressPoints = 0;
	dl.currentProgressPoint = 0;
	dl.resumeProgressPoint = 0;

	var _modules, 
		_themes, 
		_install_key,
		_locked = false;

	dl.constructor = $(function() {

		dl.elBar = $('#download_progress .progress .bar');
		dl.elMsg = $('#download_progress small');

		$.ajaxSetup({
			beforeSend: function(request) {
				request.setRequestHeader('PHPR-REMOTE-EVENT', '1');
			}
		});

	});

	dl.setRequestInfo = function(url, install_key) {
		dl.url = url;
		_install_key = install_key;
	}

	dl.setModules = function(modules) {
		_modules = modules;
	}
	
	dl.setThemes = function(themes) {
		_themes = themes;
	}

	dl.startDownload = function() {
		if (_locked)
			return;

		dl.setLock(true);

		// Reset
		dl.resetEvents();
		dl.resumeProgressPoint = 0;
		
		// Init
		dl.spoolEvents();

		// Exec
		$.waterfall.apply(dl, dl.eventChain)
			.fail(function(xhr, status, message){ dl.progressError(xhr.responseText); })
			.done(function(){ dl.progressDone(); })
			.always(function(){ dl.setLock(false); });
		
		return false;
	}

	dl.retryDownload = function() {
		if (_locked)
			return;

		dl.setLock(true);

		dl.resetEvents();
		
		// Init
		dl.spoolEvents();

		// Exec
		$.waterfall.apply(dl, dl.eventChain)
			.fail(function(xhr, status, message){ dl.progressError(xhr.responseText); })
			.done(function(){ dl.progressDone(); })
			.always(function(){ dl.setLock(false); });

		return false;
	}

	dl.setLock = function(value) {
		_locked = value;
		$('#download_btn, #retry_btn').prop('disabled', _locked);
	}

	dl.resetEvents = function() {
		dl.eventChain = [];
		dl.totalProgressPoints = 0;
		dl.currentProgressPoint = 0;
		dl.setProgressPoints(_modules.length + _themes.length);

		// Reset the UI
		dl.progressResume();
	}

	dl.spoolEvents = function() {

		//
		// Modules
		// 
		
		$.each(_modules, function(key, module){
			
			dl.eventChain.push(function() { 
				if (dl.pushProgressForward('Downloading module: ' + module))
					return true; // Skip

				return $.post(dl.url, {
					step: 'request_package',
					package_name: module,
					package_type: 'module',
					install_key: _install_key
				});
			});

			dl.eventChain.push(function() { 
				if (dl.pushProgressForward('Uncompressing module: ' + module))
					return true; // Skip

				return $.post(dl.url, {
					step: 'unzip_package',
					package_name: module,
					package_type: 'module',
					install_key: _install_key
				});
			});
		});

		//
		// Themes
		// 
		
		$.each(_themes, function(key, theme){
			
			dl.eventChain.push(function() { 
				if (dl.pushProgressForward('Downloading theme: ' + theme))
					return true; // Skip

				return $.post(dl.url, {
					step: 'request_package',
					package_name: theme,
					package_type: 'theme',
					install_key: _install_key
				});
			});

			dl.eventChain.push(function() { 
				if (dl.pushProgressForward('Uncompressing theme: ' + theme))
					return true; // Skip

				return $.post(dl.url, {
					step: 'unzip_package',
					package_name: theme,
					package_type: 'theme',
					install_key: _install_key
				});
			});
		});

		//
		// Installation
		// 
		
		dl.eventChain.push(function() {
			if (dl.pushProgressForward('Creating system files'))
				return true; // Skip

			return $.post(dl.url, {
				step: 'install_phpr',
				action: 'generate_files',
				install_key: _install_key
			});
		});

		dl.eventChain.push(function() {
			if (dl.pushProgressForward('Building database structure'))
				return true; // Skip

			return $.post(dl.url, {
				step: 'install_phpr',
				action: 'build_database',
				install_key: _install_key
			});
		});

		dl.eventChain.push(function() {
			if (dl.pushProgressForward('Creating administrator account'))
				return true; // Skip

			return $.post(dl.url, {
				step: 'install_phpr',
				action: 'create_admin',
				install_key: _install_key
			});
		});

		dl.eventChain.push(function() {
			if (dl.pushProgressForward('Installing theme components'))
				return true; // Skip

			return $.post(dl.url, {
				step: 'install_phpr',
				action: 'install_theme',
				install_key: _install_key
			});
		});

	}

	dl.setProgressPoints = function(package_num) {
		dl.totalProgressPoints += package_num; // Download
		dl.totalProgressPoints += package_num; // Unzip
		dl.totalProgressPoints += 1; // Generate files
		dl.totalProgressPoints += 1; // Build database
		dl.totalProgressPoints += 1; // Create admin
		dl.totalProgressPoints += 1; // Install theme
		dl.totalProgressPoints += 1; // Verify
	}

	dl.pushProgressForward = function(message, force) {
		dl.currentProgressPoint++;

		if (dl.currentProgressPoint <= dl.resumeProgressPoint && !force)
			return true;

		var percent_chunk = 100 / dl.totalProgressPoints;
		var percent_amt = Math.round(percent_chunk * dl.currentProgressPoint);
		dl.setProgress(message, percent_amt);
		return false;
	}

	dl.pushProgressBack = function(message) {
		dl.elMsg.removeClass('tick').addClass('loading');
		$('#download_progress').removeClass('success');
		$('#next_btn, #next_txt').hide();

		dl.currentProgressPoint -= 2;
		dl.pushProgressForward(message, true);

		// Resume
		$('#download_btn').hide();
		$('#retry_btn').show();
		dl.resumeProgressPoint = dl.currentProgressPoint;
	}

	dl.setProgress = function(message, percent) {
		dl.elMsg.text(message);
		dl.elBar.attr('data-percentage', percent).progressbar({
			use_percentage: true,
			display_text: 2,
			update: dl.progressUpdate
		});
	}

	dl.progressUpdate = function(amount) {
		if (amount == 100)  {
			dl.elMsg.addClass('tick').removeClass('loading');
			$('#download_progress').addClass('success');
			$('#download_btn, #retry_btn').hide();
			$('#next_btn, #next_txt').show();
			dl.elMsg.text('Download complete');
		}
	}

	dl.progressDone = function() {
		dl.setProgress('Verifying installation', 100);
	}

	dl.progressError = function(message){
		if (!message)
			message = 'Download error...';
		
		dl.elBar.addClass('bar-danger');
		dl.pushProgressBack(message);
		dl.elMsg.addClass('cross').removeClass('loading');
		$('#download_progress').addClass('error');
	}

	dl.progressResume = function() {
		dl.elMsg.removeClass('cross').addClass('loading');
		dl.elBar.removeClass('bar-danger');
	}

	return dl;
}(Phpr_Downloader || {}, jQuery));

(function($) {
/**
 * Runs functions given in arguments in series, each functions passing their results to the next one.
 * Return jQuery Deferred object.
 *
 * @author Dmitry (dio) Levashov, dio@std42.ru
 * @return jQuery.Deferred
 */
$.waterfall = function() {
	var steps   = [],
		dfrd    = $.Deferred(),
		pointer = 0;

	$.each(arguments, function(i, a) {
		steps.push(function() {
			var args = [].slice.apply(arguments), d;

			if (typeof(a) == 'function') {
				if (!((d = a.apply(null, args)) && d.promise)) {
					d = $.Deferred()[d === false ? 'reject' : 'resolve'](d);
				}
			} else if (a && a.promise) {
				d = a;
			} else {
				d = $.Deferred()[a === false ? 'reject' : 'resolve'](a);
			}

			d.fail(function() {
				dfrd.reject.apply(dfrd, [].slice.apply(arguments));
			})
			.done(function(data) {
				pointer++;
				args.push(data);

				pointer == steps.length
					? dfrd.resolve.apply(dfrd, args)
					: steps[pointer].apply(null, args);
			});
		});
	});

	steps.length ? steps[0]() : dfrd.resolve();

	return dfrd;
}

})(jQuery);