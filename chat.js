var instanse = false;
var state;
var file;

function Chat (){
    this.update = updateChat;
    this.send = sendChat;
	this.getState = getStateOfChat;
	this.initiate = initiateChat;
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
function updateChat(){
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
		 setTimeout(updateChat, 1500);
	 }
}

//send the message
function sendChat(message, nickname)
{       
    updateChat();
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
			   updateChat();
		   },
		});
}

function initiateChat(){
	 if(!instanse){
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
}
