(function($){
	$(document).ready(function(){	
		var qrHash = $('meta[name=qrHash]').attr('content');
		if ($('body').hasClass('login')){	
			var hashUrl = 'http://chart.googleapis.com/chart?chs=300x300&cht=qr&chl='+encodeURIComponent(window.location.hostname+window.location.pathname.substring(0,window.location.pathname.length-12)+'wp-admin/options-general.php?page=qr-login&qrHash='+qrHash);
	
			$('#login h1').append('<img id="qrHash">');
			
			var loginOffset = $('#loginform').offset();
			$('#qrHash').attr('src',hashUrl).css({'display':'block','position':'absolute','right':(loginOffset.left-310),'top':loginOffset.top-55});					
			var sendAjax = function() {
				$.post(qrLoginAjaxRequest.ajaxurl, { 
					action : 'ajax-qrLogin',
			        qrHash : qrHash,
			        QRnonce : qrLoginAjaxRequest.qrLoginNonce
			    },function( response ) {
			    	if (response.hash == qrHash){
				        window.location = "http://"+window.location.hostname+"/wp-login.php?qrHash="+response.hash;
				    } else {
				    	sendAjax();
				    }
			    });
			};
			sendAjax();
	    }
	});		
})(jQuery);