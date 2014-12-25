(function($){
	$(document).ready(function(){	
		var qrHash = $('meta[name=qrHash]').attr('content');
		if ($('body').hasClass('login') && $('body').hasClass('wp-core-ui') && $('#login').length > 0) {	
			var hashUrl = 'http://chart.googleapis.com/chart?chs=300x300&cht=qr&chl='+encodeURIComponent($('meta[name=qrHash]').attr('wpurl')+'/wp-admin/options-general.php?page=qr-login&qrHash='+qrHash);
	
			$('#login h1').after('<div id="qrHash"><img ></div>');
			
			var loginOffset = $('#loginform').offset();
			$('#qrHash img').attr('src',hashUrl).css({'display':'block','position':'absolute','right':(loginOffset.left-310),'top':loginOffset.top-55});					
			var sendAjax = function() {
				$.post(qrLoginAjaxRequest.ajaxurl, { 
					action : 'ajax-qrLogin',
			        qrHash : qrHash,
			        QRnonce : qrLoginAjaxRequest.qrLoginNonce
			    },function( response ){
			    	if (response === qrHash){
                        var hasQuery = window.location.href.indexOf("?") > -1;
				        window.location = window.location.href+((hasQuery) ? "&" : "?"  )+"qrHash="+response;
				    } else if(response === 'hash gone') {
                        $('#qrHash').html('<h2>Oh no! You waited too long!</h2><br><p>Please reload the page and try No More Passwords again.</p><p>If you see this message and less than 5 mintues have passed since you got here please <a href="http://nopasswords.website/contact/" target="_BLANK">let us know!</a></p>');
                    } else {
				    	sendAjax();
				    }
			    });
			};
			sendAjax();
	    }
	});		
})(jQuery);