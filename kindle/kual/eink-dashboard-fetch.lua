local url, output = arg[1], arg[2]
if not url or not output then
    io.stderr:write("Usage: eink-dashboard-fetch.lua URL OUTPUT\n")
    os.exit(2)
end

dofile("/mnt/us/koreader/setupkoenv.lua")

local https = require("ssl.https")
local ltn12 = require("ltn12")
local file, err = io.open(output, "wb")
if not file then
    io.stderr:write("open failed: " .. tostring(err) .. "\n")
    os.exit(1)
end

local ok, code, _, status = https.request({
    url = url,
    sink = ltn12.sink.file(file),
    protocol = "any",
    options = {"all", "no_sslv2", "no_sslv3", "no_tlsv1"},
    verify = "peer",
    cafile = "/mnt/us/koreader/data/ca-bundle.crt",
})

if not ok or tonumber(code) ~= 200 then
    os.remove(output)
    io.stderr:write("HTTPS request failed: " .. tostring(code or status) .. "\n")
    os.exit(1)
end
