console.log(getDateTime() + " Ready now.\n**********************************");
var Config = {
    portSocket: 10011,//websocket端口 如果前端配置了https，你需要反代一下这个端口到443实现wss
    portHttp: 10012,//api端通过这个端口的http服务来获取在线用户信息
    secret: "wss_eggedu_cn",
    apiUrl: "https://api.bbbug.com",//这里修改为你部署的API端地址
};
var websocket = require("nodejs-websocket"),
    crypto = require('crypto'),
    http = require('http'),
    https = require('https');
var webSocketServer = websocket.createServer(function (conn) {
    var query = login(conn.path);
    if (!query) {
        logError("客户端登录失败", {
            path: conn.path,
            remoteAddress: getRemoteAddress(conn),
        });
        conn.close();
    } else {
        logInfo("客户端连接成功", {
            account: query.account,
            channel: query.channel,
            remoteAddress: getRemoteAddress(conn),
        });
        fetchNowSongAndSend(conn, query, "connect");
        sendOnlineList(query.channel);
    }
    conn.on("close", function (code, reason) {
        logWarn("客户端断开", {
            account: query && query.account || "",
            channel: query && query.channel || "",
            code: code,
            reason: reason ? reason.toString() : "",
            remoteAddress: getRemoteAddress(conn),
        });
        if (query && query.channel) {
            sendOnlineList(query.channel);
        }
    });
    conn.on("error", function (code, reason) {
        logError("客户端连接异常", {
            account: query && query.account || "",
            channel: query && query.channel || "",
            code: code,
            reason: reason ? reason.toString() : "",
            remoteAddress: getRemoteAddress(conn),
        });
        if (query && query.channel) {
            sendOnlineList(query.channel);
        }
    });
    conn.on("text", function (msg) {
        if (msg == 'getNowSong') {
            logInfo("客户端请求当前歌曲", {
                account: query && query.account || "",
                channel: query && query.channel || "",
            });
            fetchNowSongAndSend(conn, query, "message");
        } else if (msg == 'bye') {
            logWarn("用户主动断开链接", {
                account: query && query.account || "",
                channel: query && query.channel || "",
            });
            conn.close();
        } else {
            logInfo("收到客户端消息", {
                account: query && query.account || "",
                channel: query && query.channel || "",
                type: "text",
                message: msg,
            });
        }
    });
});
webSocketServer.listen(Config.portSocket);
console.log(getDateTime() + " 服务启动成功(" + Config.portSocket.toString() + ")Websocket");
checkConnection();

var hasLoggedZeroConnections = false;

function checkConnection() {
    var count = webSocketServer.connections.length;
    if (count === 0) {
        if (!hasLoggedZeroConnections) {
            console.log(getDateTime() + " 当前在线连接数：(" + count + ")");
            hasLoggedZeroConnections = true;
        }
    } else {
        console.log(getDateTime() + " 当前在线连接数：(" + count + ")");
        hasLoggedZeroConnections = false;
    }
    setTimeout(function () {
        checkConnection();
    },
        5000);
}

function sendOnlineList(channel) {
    var url = Config.apiUrl + '/api/user/online?sync=yes&room_id=' + channel;
    logInfo("开始同步在线列表", {
        channel: channel,
        url: url,
    });
    https.get(url, function (res) {
        var dataString = "";
        res.on("data", function (data) {
            dataString += data;
        });
        res.on("end", function () {
            var response;
            webSocketServer.connections.forEach(function (conn) {
                try {
                    var query = login(conn.path);
                    if (query.channel == channel) {
                        if (!response) {
                            response = JSON.parse(dataString);
                        }
                        conn.sendText(JSON.stringify({
                            type: "online",
                            channel: channel,
                            data: response.data
                        }));
                    }
                } catch (e) {
                    logError("在线列表广播失败", {
                        channel: channel,
                        path: conn.path,
                        message: e.message,
                    });
                }
            });
            logInfo("在线列表同步完成", {
                channel: channel,
                onlineCount: response && response.data ? response.data.length : 0,
            });
        });
    }).on("error", function (e) {
        logError("在线列表接口请求失败", {
            channel: channel,
            url: url,
            message: e.message,
        });
    });
}

var http = require('http');
var url = require('url');
var querystring = require('querystring');
var httpServer = http.createServer(function (req, res) {
    if (req.method.toUpperCase() == 'POST') {
        res.writeHead(200, {
            'Content-Type': 'application/json;charset=utf-8'
        });
        var postData = '';
        req.on('data', function (chunk) {
            postData += chunk;
        });
        req.on('end', function () {
            postData = querystring.parse(postData);
            logInfo("收到 HTTP 推送请求", {
                type: postData.type || "",
                to: postData.to || "",
                hasToken: !!postData.token,
                payloadLength: (postData.msg || "").length,
            });
            if (postData.token == sha1(Config.secret)) {
                var sendCount = 0;
                switch (postData.type) {
                    case 'chat':
                        webSocketServer.connections.forEach(function (conn) {
                            var query = new QueryString(conn.path);
                            if (query.account == postData.to) {
                                conn.sendText(postData.msg);
                                sendCount++;
                            }
                        });
                        break;
                    case 'channel':
                        webSocketServer.connections.forEach(function (conn) {
                            var query = new QueryString(conn.path);
                            if (query.channel == postData.to) {
                                conn.sendText(postData.msg);
                                sendCount++;
                            }
                        });
                        break;
                    case 'system':
                        webSocketServer.connections.forEach(function (conn) {
                            conn.sendText(postData.msg);
                            sendCount++;
                        });
                        break;
                    default:
                        logWarn("收到未知 HTTP 推送类型", {
                            type: postData.type || "",
                            to: postData.to || "",
                        });
                }
                logInfo("HTTP 推送请求处理完成", {
                    type: postData.type || "",
                    to: postData.to || "",
                    sendCount: sendCount,
                });
                res.end();
            } else {
                logWarn("HTTP 推送鉴权失败", {
                    type: postData.type || "",
                    to: postData.to || "",
                });
                res.end("token error");
            }
        });
    } else if (req.method.toUpperCase() == 'GET') {
        res.writeHead(200, {
            'Content-Type': 'application/json;charset=utf-8'
        });
        var onlineList = [];
        var gets = new QueryString(req.url);
        webSocketServer.connections.forEach(function (conn) {
            var query = new QueryString(conn.path);
            if (gets.channel) {
                if (gets.channel == query.channel) {
                    onlineList.push(query.account);
                }
            } else {
                onlineList.push(query.account);
            }
        });
        res.end(JSON.stringify(onlineList));
    } else {
        res.writeHead(403, {
            'Content-Type': 'application/json;charset=utf-8'
        });
        res.end();
    }
});
httpServer.listen(Config.portHttp);
console.log(getDateTime() + " Web服务{" + Config.portHttp + "}启动成功!");


function getTime() {
    var now = new Date();
    var hours = now.getHours();
    var minutes = now.getMinutes();
    var seconds = now.getSeconds();
    if (hours < 10) {
        hours = "0" + hours;
    }
    if (minutes < 10) {
        minutes = "0" + minutes;
    }
    if (seconds < 10) {
        seconds = "0" + seconds;
    }
    return hours + ":" + minutes + ":" + seconds;
}

function getDateTime() {
    var now = new Date();
    var year = now.getFullYear();
    var month = now.getMonth() + 1;
    var day = now.getDate();
    if (month < 10) {
        month = "0" + month;
    }
    if (day < 10) {
        day = "0" + day;
    }
    return year + "-" + month + "-" + day + " " + getTime();
}

function debug(message) {
    console.log(getDateTime() + " : " + message);
}

function logInfo(message, context) {
    console.log(formatLog("INFO", message, context));
}

function logWarn(message, context) {
    console.warn(formatLog("WARN", message, context));
}

function logError(message, context) {
    console.error(formatLog("ERROR", message, context));
}

function formatLog(level, message, context) {
    var log = getDateTime() + " [" + level + "] " + message;
    if (context && Object.keys(context).length > 0) {
        try {
            log += " " + JSON.stringify(context);
        } catch (e) {
            log += " " + String(context);
        }
    }
    return log;
}

function getRemoteAddress(conn) {
    return conn && conn.socket && conn.socket.remoteAddress ? conn.socket.remoteAddress : "";
}

function fetchNowSongAndSend(conn, query, source) {
    var url = Config.apiUrl + '/api/song/now?room_id=' + query.channel;
    logInfo("开始请求当前歌曲", {
        account: query.account,
        channel: query.channel,
        source: source,
        url: url,
    });
    https.get(url, function (res) {
        var dataString = "";
        res.on("data", function (data) {
            dataString += data;
        });
        res.on("end", function () {
            try {
                var response = JSON.parse(dataString);
                conn.sendText(JSON.stringify({
                    type: response.type,
                    time: 'now',
                    song: response.song || null,
                    story: response.story || null,
                    since: response.since || 0,
                    count: response.count || 0,
                    user: response.user || null,
                    at: response.at || false,
                }));
                logInfo("当前歌曲已发送给客户端", {
                    account: query.account,
                    channel: query.channel,
                    source: source,
                    type: response.type || "",
                    hasSong: !!response.song,
                    count: response.count || 0,
                });
            } catch (e) {
                logError("当前歌曲响应解析失败", {
                    account: query.account,
                    channel: query.channel,
                    source: source,
                    message: e.message,
                    body: dataString,
                });
            }
        });
    }).on("error", function (e) {
        logError("当前歌曲接口请求失败", {
            account: query.account,
            channel: query.channel,
            source: source,
            url: url,
            message: e.message,
        });
    });
}

function login(url) {
    var query = new QueryString(url);
    if (sha1("account" + query.account + "channel" + query.channel + 'salt' + query.channel) == query.ticket) {
        return query;
    } else {
        return false;
    }
}

function QueryString(url) {
    var name, value;
    url = url.replace("/?", "");
    var arr = url.split("&"); //各个参数放到数组里
    for (var i = 0; i < arr.length; i++) {
        num = arr[i].indexOf("=");
        if (num > 0) {
            name = arr[i].substring(0, num);
            value = arr[i].substr(num + 1);
            this[name] = value;
        }
    }
}

function getTimeStamp() {
    return Date.parse(new Date()) / 1000;
}

function sha1(str) {
    var sha1 = crypto.createHash("sha1"); //定义加密方式:md5不可逆,此处的md5可以换成任意hash加密的方法名称；
    sha1.update(str);
    var res = sha1.digest("hex"); //加密后的值d
    return res;
}
