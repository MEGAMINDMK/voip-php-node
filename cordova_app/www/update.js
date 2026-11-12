document.addEventListener("deviceready", onDeviceReady, false);

const SERVER_URL = "https://example.com/check.php";

async function onDeviceReady() {

    console.log("Device ready, checking update...");

    // ===============================
    // INTERNET CHECK (YOUR ORIGINAL)
    // ===============================
    if (navigator.connection) {

        const connectionType = navigator.connection.type;

        if (connectionType === Connection.NONE || connectionType === Connection.UNKNOWN) {

            console.log("No internet connection, skipping update check");

            setTimeout(function () {
                window.location.href = 'error.html';
            }, 5000);

            return;
        }

    } else if (!navigator.onLine) {

        console.log("No internet connection, skipping update check");

        setTimeout(function () {
            window.location.href = 'error.html';
        }, 5000);

        return;
    }

    // ===============================
    // 1. DEVICE VERIFICATION (ADDED)
    // ===============================
    const ok = await verifyAccess();

    if (!ok.allowed) {

        alert(ok.error || "Access Denied");

        setTimeout(() => {
            window.location.href = "error.html";
        }, 3000);

        return;
    }

    // ===============================
    // 2. YOUR ORIGINAL UPDATE CHECK (UNCHANGED)
    // ===============================
    let currentBuild = AppVersion.build;

    fetch("https://ktxbot.netlify.app/version.xml")
        .then(response => response.text())
        .then(str => (new window.DOMParser()).parseFromString(str, "text/xml"))
        .then(data => {

            let latestVersion = data.getElementsByTagName("version")[0].textContent;
            let apkUrl = data.getElementsByTagName("url")[0].textContent;

            console.log("Current build:", currentBuild, "Latest build:", latestVersion);

            if (parseInt(latestVersion) > parseInt(currentBuild)) {

                console.log("New update available!");
                downloadAndInstall(apkUrl);

            } else {

                console.log("App is up to date");

                setTimeout(function () {
                    window.location.href = 'http://localhost/main.html';
                }, 5000);
            }

        })
        .catch(err => {

            console.error("Update check failed:", err);

            setTimeout(function () {
                window.location.href = 'http://localhost/main.html';
            }, 5000);
        });
}


// ===============================
// VERIFY ACCESS (ADDED ONLY)
// ===============================
async function verifyAccess() {

    try {

        const ip = await getPublicIP();
        const userId = device.uuid || "unknown";

        const userData = {

            userId: userId,

            mac: device.uuid || "Unknown",
            diskSerial: device.uuid || "Unknown",
            motherboardSerial: device.uuid || "Unknown",

            systemName: (device.manufacturer || "Unknown") + " " + (device.model || "Unknown"),

            userIP: ip,

            date: new Date().toISOString().split("T")[0],
            time: new Date().toTimeString().split(" ")[0]
        };

        console.log("Sending device info:", userData);

        // register
        try {
            await fetch(`${SERVER_URL}?action=register`, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(userData)
            });
        } catch (e) {
            console.log("Register failed:", e);
        }

        // check users (FIXED FOR YOUR PHP)
        const res = await fetch(`${SERVER_URL}?action=users`);
        const users = await res.json();

        const data = users[userId] || null;

        console.log("User from server:", data);

        if (!data) {
            return {
                allowed: false,
                banned: false,
                error: "User not found"
            };
        }

        if (data.banned === true) {
            return {
                allowed: false,
                banned: true,
                error: "You are banned"
            };
        }

        return {
            allowed: true,
            banned: false,
            error: null
        };

    } catch (err) {

        console.log("Verification error:", err);

        return {
            allowed: false,
            banned: false,
            error: "Internet error"
        };
    }
}


// ===============================
// GET PUBLIC IP
// ===============================
async function getPublicIP() {

    try {

        const res = await fetch("https://api64.ipify.org?format=json");
        const data = await res.json();

        return data.ip || "Unknown";

    } catch {

        return "Unknown";
    }
}


// ===============================
// YOUR ORIGINAL DOWNLOAD FUNCTION (UNCHANGED)
// ===============================
function downloadAndInstall(apkUrl) {

    let ask = confirm(
        "A newer version of this app is available.\n\nWould you like to download the latest update?"
    );

    if (!ask) {

        console.log("User cancelled update.");

        setTimeout(function () {
            window.location.href = 'http://localhost/main.html';
        }, 5000);

        return;
    }

    const chromeUrl = 'googlechrome://navigate?url=' + apkUrl;

    console.log("Opening Chrome with URL:", apkUrl);

    cordova.InAppBrowser.open(chromeUrl, '_system', 'location=no');
}