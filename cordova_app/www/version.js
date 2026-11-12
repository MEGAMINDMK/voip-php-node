var app = {
    initialize: function() {
        this.bindEvents();
    },
    bindEvents: function() {
        document.addEventListener('deviceready', this.onDeviceReady, false);
    },
    onDeviceReady: function() {
        app.showVersion();
    },
    showVersion: function() {
        // cordova-plugin-appversion gives global AppVersion
        document.getElementById('version').innerText = AppVersion.version;
        console.log("App version:", AppVersion.version);
        console.log("Build number:", AppVersion.build);
    }
};

app.initialize();
