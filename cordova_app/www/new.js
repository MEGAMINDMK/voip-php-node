document.addEventListener('deviceready', function() {
    // Initialize OneSignal
    window.plugins.OneSignal.Debug.setLogLevel(6);
	//3e28cd07-70c9-4a89-ba89-f47ddf53cae3 app id
    //window.plugins.OneSignal.initialize("012fe796-bd9a-4bbd-b729-990d1b5358e9");
	//https://dashboard.onesignal.com/organizations/de1790df-2784-420b-a0df-b63accaf48cd/keys_and_ids
window.plugins.OneSignal.initialize("eaf16a07-f5eb-4c26-b89a-52f9d945b57a");
 /*window.plugins.OneSignal.setNotificationOpenedHandler(function(jsonData) {
        console.log('notificationOpenedCallback: ' + JSON.stringify(jsonData));
    });*/
    // Notification Click Handler
 /*   const notificationClickListener = (event) => {
        console.log('Notification event:', JSON.stringify(event)); // Debugging log

        const notification = event.notification || {};
        const additionalData = notification.additionalData || {};
        const url = additionalData.url;

        // Correct way to get actionId
        const actionId = event.result ? event.result.actionId : null;
        
        console.log('Extracted actionId:', actionId); // Debugging log

        if (url) {
            if (actionId === 'open_app') {
                console.log('Opening in app');
                window.location.href = url;
            } else if (actionId === 'open_web') {
                console.log('Opening in external Chrome browser');
                const chromeUrl = 'googlechrome://navigate?url=' + url;
                cordova.InAppBrowser.open(chromeUrl, '_system');
            } else {
                console.log('Unknown actionId:', actionId);
            }
        } else {
            console.log('URL is missing in additionalData');
        }
    };

    window.plugins.OneSignal.Notifications.addEventListener("click", notificationClickListener); */

    // Prompt the user for notification permissions
    window.plugins.OneSignal.Notifications.requestPermission(true).then((accepted) => {
        console.log("User accepted notifications: " + accepted);
    });
	
});
