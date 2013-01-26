var Phpr_Downloader = (function(dl, $){

    dl.url = null;
    dl.el_bar = null;
    dl.el_msg = null;
    dl.event_chain = [];

    dl.total_progress_points = 1;
    dl.current_progress_point = 0;

    dl.constructor = $(function() {

        dl.el_bar = $('#download_progress .progress .bar');
        dl.el_msg = $('#download_progress small');

        $.ajaxSetup({
            beforeSend: function(request) {
                request.setRequestHeader('PHPR-REMOTE-EVENT', '1');
            }
        });

    });

    dl.get_packages = function(url, packages) {

        dl.url = url;
        dl.el_msg.addClass('loading');

        dl.set_progress_points(packages.length);

        $.each(packages, function(key, package){
            
            dl.event_chain.push(function() { 
                dl.push_progress_forward('Requesting package: ' + package);
                return $.post(dl.url, {
                    step: 'request_package', 
                    package_name: package
                });
            });

            dl.event_chain.push(function() { 
                dl.push_progress_forward('Uncompressing package: ' + package);
                return $.post(dl.url, {
                    step: 'unzip_package', 
                    package_name: package
                });
            });
        });

        $.waterfall.apply(dl, dl.event_chain)
            .fail(function(xhr, status, message){ dl.progress_error(xhr.responseText); })
            .done(function(){ dl.progress_done(); });
    }

    dl.set_progress_points = function(package_num) {
        dl.total_progress_points = package_num * 2;
    }

    dl.push_progress_forward = function(message) {
        dl.current_progress_point++;
        var percent_chunk = 100 / dl.total_progress_points;
        var percent_amt = Math.round(percent_chunk * dl.current_progress_point);
        dl.set_progress(message, percent_amt);
    }

    dl.push_progress_back = function(message) {
        // In case 100
        dl.el_msg.removeClass('tick');
        $('#download_progress').removeClass('success');
        $('#download_btn').show();
        $('#next_btn, #next_txt').hide();

        dl.current_progress_point -= 2;
        dl.push_progress_forward(message);
    }

    dl.set_progress = function(message, percent) {
        dl.el_msg.text(message);
        dl.el_bar.attr('data-percentage', percent).progressbar({
            use_percentage: true,
            transition_delay: 500,
            display_text: 2,
            update: dl.progress_update
        });
    }

    dl.progress_update = function(amount) {
        if (amount == 100)  {
            dl.el_msg.addClass('tick');
            $('#download_progress').addClass('success');
            $('#download_btn').hide();
            $('#next_btn, #next_txt').show();
            dl.el_msg.text('Download complete');
        }
    }
    dl.progress_done = function() {
        dl.set_progress('Verifying packages', 100);
    }

    dl.progress_error = function(message){
        if (!message)
            message = 'Download error...';
        
        dl.push_progress_back(message);
        dl.el_msg.addClass('cross');
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