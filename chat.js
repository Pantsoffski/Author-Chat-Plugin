var instanse = false;
var state;
var file;

function Chat (){
    this.update = ppUpdateChat;
    this.send = ppSendChat;
	this.getState = getStateOfChat;
	this.initiate = ppInitiateChat;
}

//gets the state of the chat
function getStateOfChat(){
	if(!instanse){
		 instanse = true;
		 jQuery.ajax({
			   type: "POST",
			   url: pluginurl,
			   data: {  
			   			'function': 'getState',
						'file': file
						},
			   dataType: "json",
			
			   success: function(data){
				   state = data.state;
				   instanse = false;
			   },
			});
	}	 
}

//Updates the chat
function ppUpdateChat(){
	 if(!instanse){
		 instanse = true;
	     jQuery.ajax({
			   type: "POST",
			   url: pluginurl,
			   data: {  
			   			'function': 'update',
						'state': state,
						'file': file
						},
			   dataType: "json",
			   success: function(data){
				   if(data.text != null){
						for (var i = 0; i < data.text.length; i++) {
                            jQuery('#chat-area').append(jQuery("<p>"+ data.text[i] +"</p>"));
                        }								  
				   }
				   document.getElementById('chat-area').scrollTop = document.getElementById('chat-area').scrollHeight;
				   instanse = false;
				   state = data.state;
			   },
			});
	 }else {
		 setTimeout(ppUpdateChat, 1500);
	 }
}

//send the message
function ppSendChat(message, nickname)
{       
    ppUpdateChat();
     jQuery.ajax({
		   type: "POST",
		   url: pluginurl,
		   data: {  
		   			'function': 'send',
					'message': message,
					'nickname': nickname,
					'file': file
				 },
		   dataType: "json",
		   success: function(data){
			   ppUpdateChat();
		   },
		});
}

function ppInitiateChat(){
		 instanse = true;
	     jQuery.ajax({
			   type: "POST",
			   url: pluginurl,
			   data: {  
			   			'function': 'initiate',
						'state': state,
						'file': file
						},
			   dataType: "json",
			   success: function(data){
				   if(data.text != null){
						for (var i = 0; i < data.text.length; i++) {
                            jQuery('#chat-area').append(jQuery("<p>"+ data.text[i] +"</p>"));
                        }								  
				   }
				   document.getElementById('chat-area').scrollTop = document.getElementById('chat-area').scrollHeight;
				   instanse = false;
				   state = data.state;
			   },
			});
}
