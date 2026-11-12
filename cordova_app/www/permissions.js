document.addEventListener('deviceready', function () {
    if (cordova.plugins.permissions) {
        var permissions = [
            'android.permission.CAMERA',
			'android.permission.RECORD_AUDIO'  
        ];

       

        function checkAndRequestPermission(permission) {
            return new Promise((resolve, reject) => {
                cordova.plugins.permissions.checkPermission(permission, function (status) {
                    if (status.hasPermission) {
                        console.log(permission + " is already granted.");
                        resolve(); // Permission granted, resolve the promise
                    } else {
                        console.log(permission + " is not granted. Requesting...");
                       
                        cordova.plugins.permissions.requestPermission(permission, function (status) {
                            if (status.hasPermission) {
                                console.log(permission + " has been granted.");
                                resolve(); // Permission granted, resolve the promise
                            } else {
                                console.log(permission + " is still denied.");
                                reject(new Error(permission + " permission denied")); // Permission denied, reject the promise
                            }
                        }, function (error) {
                            console.log("Error requesting " + permission + ": " + error);
                            reject(error); // Handle error, reject the promise
                        });
                    }
                }, function (error) {
                    console.log("Error checking " + permission + ": " + error);
                    reject(error); // Handle error, reject the promise
                });
            });
        }

        function checkPermissionsSequentially(permissions) {
            var promise = Promise.resolve();

            permissions.forEach(permission => {
                promise = promise.then(() => checkAndRequestPermission(permission));
            });

            return promise;
        }

        // Expose the function globally so it can be called from index.html
        window.requestAllPermissions = function() {
    return checkPermissionsSequentially(permissions);
};

// Run automatically when app starts
window.requestAllPermissions();
    }
});
