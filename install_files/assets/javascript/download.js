var Phpr_Downloader = (function(dl, $){

	dl.url = null;
	dl.elBar = null;
	dl.elMsg = null;
	dl.eventChain = [];

	dl.totalProgressPoints = 0;
	dl.currentProgressPoint = 0;

	var _packages, 
		_install_key;

	dl.constructor = $(function() {

		dl.elBar = $('#download_progress .progress .bar');
		dl.elMsg = $('#download_progress small');

		$.ajaxSetup({
			beforeSend: function(request) {
				request.setRequestHeader('PHPR-REMOTE-EVENT', '1');
			}
		});

	});

	dl.setPackages = function(url, packages, install_key) {
		dl.url = url;
		_packages = packages;
		_install_key = install_key;
	}

	dl.startDownload = function() {
		dl.elMsg.removeClass('cross').addClass('loading');
		dl.setProgressPoints(_packages.length);

		dl.spoolEvents();

		$.waterfall.apply(dl, dl.eventChain)
			.fail(function(xhr, status, message){ dl.progressError(xhr.responseText); })
			.done(function(){ dl.progressDone(); });
		
		return false;
	}

	dl.spoolEvents = function() {

		// Packages
		$.each(packages, function(key, package){
			
			dl.eventChain.push(function() { 
				dl.pushProgressForward('Downloading package: ' + package);
				return $.post(dl.url, {
					step: 'request_package', 
					package_name: package,
					install_key: install_key
				});
			});

			dl.eventChain.push(function() { 
				dl.pushProgressForward('Uncompressing package: ' + package);
				return $.post(dl.url, {
					step: 'unzip_package', 
					package_name: package,
					install_key: install_key
				});
			});
		});

		// Installation
		dl.eventChain.push(function() {
			dl.pushProgressForward('Creating system files');
			return $.post(dl.url, {
				step: 'install_phpr',
				action: 'generate_files',
				install_key: install_key
			});
		});

		dl.eventChain.push(function() {
			dl.pushProgressForward('Building database schema');
			return $.post(dl.url, {
				step: 'install_phpr',
				action: 'build_database',
				install_key: install_key
			});
		});

		dl.eventChain.push(function() {
			dl.pushProgressForward('Creating administrator account');
			return $.post(dl.url, {
				step: 'install_phpr',
				action: 'create_admin',
				install_key: install_key
			});
		});

		dl.eventChain.push(function() {
			dl.pushProgressForward('Installing theme components');
			return $.post(dl.url, {
				step: 'install_phpr',
				action: 'install_theme',
				install_key: install_key
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

	dl.pushProgressForward = function(message) {
		dl.currentProgressPoint++;
		var percent_chunk = 100 / dl.totalProgressPoints;
		var percent_amt = Math.round(percent_chunk * dl.currentProgressPoint);
		dl.setProgress(message, percent_amt);
	}

	dl.pushProgressBack = function(message) {
		// In case 100
		dl.elMsg.removeClass('tick');
		$('#download_progress').removeClass('success');
		$('#download_btn').show();
		$('#next_btn, #next_txt').hide();

		dl.currentProgressPoint -= 2;
		dl.pushProgressForward(message);
	}

	dl.setProgress = function(message, percent) {
		dl.elMsg.text(message);
		dl.elBar.attr('data-percentage', percent).progressbar({
			use_percentage: true,
			transition_delay: 500,
			display_text: 2,
			update: dl.progressUpdate
		});
	}

	dl.progressUpdate = function(amount) {
		if (amount == 100)  {
			dl.elMsg.addClass('tick');
			$('#download_progress').addClass('success');
			$('#download_btn').hide();
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
		
		dl.pushProgressBack(message);
		dl.elMsg.addClass('cross');
		$('#download_progress').addClass('error');
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