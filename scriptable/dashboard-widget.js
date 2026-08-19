// Private dashboard widget for Scriptable.
// First run: paste a private /viewer/<device>/<token> URL from your deployment.
// The private URL is stored only in the local Keychain.

const KEY = "remote-eink-dashboard.viewer-url";
const BACKGROUND_FILE = "remote-eink-dashboard.transparent-background.png";
const DEFAULT_REFRESH_MINUTES = 15;
const files = FileManager.local();
const L = {
  title: "\u58a8\u6c34\u770b\u677f",
  city: "--",
  pin: "\ud83d\udccd",
  cloud: "\u2601\ufe0f",
  degree: "\u00b0",
  middle: "\u00b7",
  bullet: "\u25cf",
  emptyBullet: "\u25cb",
  configTitle: "\u914d\u7f6e\u58a8\u6c34\u770b\u677f",
  configMessage: "\u7c98\u8d34\u4f60\u81ea\u5df1\u90e8\u7f72\u7684\u79c1\u6709 viewer \u5730\u5740\u3002\u5b83\u53ea\u4fdd\u5b58\u5728\u672c\u673a Keychain\u3002",
  save: "\u4fdd\u5b58",
  cancel: "\u53d6\u6d88",
  preview: "\u9884\u89c8",
  transparent: "\u8bbe\u7f6e\u900f\u660e\u767d\u5b57",
  solid: "\u6062\u590d\u5361\u7247\u5e95\u8272",
  relink: "\u91cd\u65b0\u8bbe\u7f6e\u5730\u5740",
  transparentTitle: "\u900f\u660e\u5c0f\u7ec4\u4ef6",
  transparentGuide: "\u5148\u5728\u7a7a\u767d\u4e3b\u5c4f\u8fdb\u5165\u6296\u52a8\u6a21\u5f0f\u5e76\u622a\u56fe\uff0c\u518d\u9009\u62e9\u8fd9\u5f20\u622a\u56fe\u3002\u9009\u7684\u5c3a\u5bf8\u548c\u4f4d\u7f6e\u5fc5\u987b\u4e0e\u5c0f\u7ec4\u4ef6\u5b9e\u9645\u4f4d\u7f6e\u4e00\u81f4\u3002",
  chooseImage: "\u9009\u62e9\u622a\u56fe",
  imageInvalid: "\u8bf7\u9009\u62e9 1206\u00d72622 \u7684\u7ad6\u5c4f\u4e3b\u5c4f\u622a\u56fe\u3002",
  chooseSize: "\u9009\u62e9\u5c0f\u7ec4\u4ef6\u5c3a\u5bf8",
  small: "\u5c0f\u53f7",
  medium: "\u4e2d\u53f7",
  large: "\u5927\u53f7",
  choosePosition: "\u9009\u62e9\u5c0f\u7ec4\u4ef6\u4f4d\u7f6e",
  top: "\u7b2c\u4e00\u884c",
  middleRow: "\u7b2c\u4e8c\u884c",
  bottom: "\u7b2c\u4e09\u884c",
  left: "\u5de6\u5217",
  right: "\u53f3\u5217",
  done: "\u5b8c\u6210",
  failed: "\u8bbe\u7f6e\u5931\u8d25",
  invalidTitle: "\u5730\u5740\u4e0d\u5bf9",
  invalidMessage: "\u8bf7\u7c98\u8d34 HTTPS viewer \u5b8c\u6574\u5730\u5740\u3002",
  confirm: "\u597d",
  serverResponse: "\u670d\u52a1\u5668\u8fd4\u56de ",
  aiWaiting: "AI \u6570\u636e\u7b49\u5f85\u540c\u6b65",
  memory: "\u5185\u5b58",
  disk: "\u78c1\u76d8",
  unit: "\u4e2a",
  normal: "\u6b63\u5e38",
  collecting: "\u91c7\u96c6\u4e2d",
  humidity: "\u6e7f\u5ea6",
  wind: "\u98ce\u529b",
  level: "\u7ea7",
  air: "\u7a7a\u6c14",
  uv: "\u7d2b\u5916\u7ebf",
  futureWaiting: "\u672a\u6765\u5929\u6c14\u7b49\u5f85\u66f4\u65b0",
  calmAdvice: "\u5929\u6c14\u5e73\u7a33\uff0c\u613f\u4f60\u4ece\u5bb9\u51fa\u884c\u3002",
  aiBalance: "AI \u4f59\u989d",
  updated: "\u66f4\u65b0 ",
  about: "\u7ea6 ",
  minuteRefresh: " \u5206\u949f\u5237\u65b0",
  initial: "\u5148\u5728 Scriptable App \u5185\u8fd0\u884c\u4e00\u6b21\u5e76\u7c98\u8d34\u79c1\u6709 viewer \u5730\u5740\u3002",
  unavailable: "\u6682\u65f6\u65e0\u6cd5\u8bfb\u53d6\u770b\u677f\u6570\u636e\u3002\n"
};

function backgroundPath() {
  return files.joinPath(files.documentsDirectory(), BACKGROUND_FILE);
}

function hasTransparentBackground() {
  return files.fileExists(backgroundPath());
}

function transparentBackground() {
  return hasTransparentBackground() ? files.readImage(backgroundPath()) : null;
}

function text(target, value, font, color, alignment) {
  const item = target.addText(String(value));
  item.font = font;
  item.textColor = color;
  item.lineLimit = 1;
  item.minimumScaleFactor = 0.65;
  if (alignment === "right") item.rightAlignText();
  if (alignment === "center") item.centerAlignText();
  return item;
}

function value(input, fallback) {
  return input === undefined || input === null || input === "" ? fallback : input;
}

function percentage(metric) {
  return metric && metric.percentage !== null && metric.percentage !== undefined ? metric.percentage + "%" : "--";
}

function widgetUrl(viewerUrl) {
  return viewerUrl.replace("/viewer/", "/widget/") + ".json";
}

async function configure() {
  const alert = new Alert();
  alert.title = L.configTitle;
  alert.message = L.configMessage;
  alert.addTextField("https://dashboard.example.com/viewer/iphone/...", "");
  alert.addAction(L.save);
  alert.addCancelAction(L.cancel);
  if (await alert.presentAlert() < 0) return null;
  const viewerUrl = alert.textFieldValue(0).trim();
  if (!/^https:\/\/[^/]+\/viewer\/[a-z0-9_-]+\/[a-f0-9]+$/.test(viewerUrl)) {
    const error = new Alert();
    error.title = L.invalidTitle;
    error.message = L.invalidMessage;
    error.addAction(L.confirm);
    await error.presentAlert();
    return null;
  }
  Keychain.set(KEY, viewerUrl);
  return viewerUrl;
}

async function viewerUrl() {
  if (Keychain.contains(KEY)) return Keychain.get(KEY);
  if (config.runsInWidget) return null;
  return await configure();
}

async function choose(title, actions) {
  const alert = new Alert();
  alert.title = title;
  for (const action of actions) alert.addAction(action);
  alert.addCancelAction(L.cancel);
  return await alert.presentAlert();
}

async function setupTransparentBackground() {
  const prompt = new Alert();
  prompt.title = L.transparentTitle;
  prompt.message = L.transparentGuide;
  prompt.addAction(L.chooseImage);
  prompt.addCancelAction(L.cancel);
  if (await prompt.presentAlert() < 0) return;

  const source = await Photos.fromLibrary();
  if (Math.round(source.size.width) !== 1206 || Math.round(source.size.height) !== 2622) {
    const error = new Alert();
    error.title = L.failed;
    error.message = L.imageInvalid;
    error.addAction(L.confirm);
    await error.presentAlert();
    return;
  }

  const sizes = [L.small, L.medium, L.large];
  const sizeChoice = await choose(L.chooseSize, sizes);
  if (sizeChoice < 0) return;
  const family = ["small", "medium", "large"][sizeChoice];
  const positions = family === "small"
    ? [L.top + " " + L.left, L.top + " " + L.right, L.middleRow + " " + L.left, L.middleRow + " " + L.right, L.bottom + " " + L.left, L.bottom + " " + L.right]
    : family === "medium"
      ? [L.top, L.middleRow, L.bottom]
      : [L.top, L.middleRow];
  const positionChoice = await choose(L.choosePosition, positions);
  if (positionChoice < 0) return;

  const layout = {small: {width: 487, height: 487}, medium: {width: 1031, height: 487}, large: {width: 1031, height: 1098}};
  const horizontal = family === "small" && positionChoice % 2 === 1 ? 633 : 86;
  const vertical = family === "small"
    ? [260, 260, 872, 872, 1483, 1483][positionChoice]
    : family === "medium"
      ? [260, 872, 1483][positionChoice]
      : [260, 872][positionChoice];
  const frame = layout[family];
  const cropped = new DrawContext();
  cropped.size = new Size(frame.width, frame.height);
  cropped.drawImageAtPoint(source, new Point(-horizontal, -vertical));
  files.writeImage(backgroundPath(), cropped.getImage());
}

async function appMenu(url) {
  const actions = [L.preview, L.transparent, L.solid, L.relink];
  const choice = await choose(L.title, actions);
  if (choice === 1) await setupTransparentBackground();
  if (choice === 2 && hasTransparentBackground()) files.remove(backgroundPath());
  if (choice === 3) return await configure();
  return url;
}

async function loadData(url) {
  const request = new Request(widgetUrl(url));
  request.timeoutInterval = 10;
  const data = await request.loadJSON();
  if (request.response.statusCode !== 200) throw new Error(L.serverResponse + request.response.statusCode);
  return data;
}

function metricLine(target, label, metric, palette) {
  const row = target.addStack();
  row.layoutHorizontally();
  text(row, label, Font.mediumSystemFont(11), palette.muted);
  row.addSpacer();
  text(row, value(metric && metric.label, "--"), Font.semiboldSystemFont(11), palette.text, "right");
}

function accountText(accounts) {
  const parts = [];
  for (const account of accounts || []) {
    if (account.source === "deepseek") {
      parts.push("DeepSeek " + value(account.summary, "--").replace(/^Balance\s*/i, ""));
      continue;
    }
    if (account.source === "grok2api") {
      const build = account.five_hour || {};
      const web = account.seven_day || {};
      parts.push("Grok Build " + value(build.used, "--") + "% · Web " + value(web.used, "--") + "%");
      continue;
    }
    const sevenDay = account.seven_day || {};
    if (sevenDay.used !== null && sevenDay.used !== undefined) parts.push(account.name + " " + sevenDay.used + "%");
  }
  return parts.join("  " + L.middle + "  ") || L.aiWaiting;
}

function makeWidget(data, openUrl) {
  const transparent = hasTransparentBackground();
  const dark = Device.isUsingDarkAppearance();
  const palette = transparent ? {
    background: new Color("000000", 0), text: Color.white(), muted: new Color("D9E5F6"), accent: new Color("9DE2FF"), good: new Color("72E0B6"), line: new Color("FFFFFF", 0.25)
  } : dark ? {
    background: new Color("101725"), card: new Color("172136"), text: new Color("EDF3FF"), muted: new Color("9EACC3"), accent: new Color("78C3E6"), good: new Color("52C99C"), line: new Color("2B3850")
  } : {
    background: new Color("EAF0FB"), card: new Color("FFFFFF"), text: new Color("14213D"), muted: new Color("60728E"), accent: new Color("3976A9"), good: new Color("188466"), line: new Color("DCE5F2")
  };
  const widget = new ListWidget();
  if (transparent) widget.backgroundImage = transparentBackground();
  else widget.backgroundColor = palette.background;
  widget.setPadding(14, 15, 13, 15);
  widget.url = openUrl || "https://dashboard.example.com";

  const refreshMinutes = Math.max(5, Number(data.refresh_minutes || DEFAULT_REFRESH_MINUTES));
  widget.refreshAfterDate = new Date(Date.now() + refreshMinutes * 60 * 1000);
  const family = config.widgetFamily || "medium";
  const weather = data.weather || {};
  const server = data.server || {};

  if (family === "accessoryInline") {
    text(widget, L.pin + " " + value(weather.city, L.city) + " " + value(weather.temperature, "--") + L.degree + " " + L.middle + " CPU " + percentage(server.cpu), Font.semiboldSystemFont(12), palette.text);
    return widget;
  }
  if (family === "accessoryCircular") {
    text(widget, value(weather.temperature, "--") + L.degree, Font.boldSystemFont(20), palette.text, "center");
    text(widget, "CPU " + percentage(server.cpu), Font.mediumSystemFont(9), palette.muted, "center");
    return widget;
  }
  if (family === "accessoryRectangular") {
    text(widget, L.pin + " " + value(weather.city, L.city) + "  " + value(weather.emoji, L.cloud) + " " + value(weather.temperature, "--") + L.degree, Font.boldSystemFont(14), palette.text);
    text(widget, value(weather.condition, "--") + " " + L.middle + " CPU " + percentage(server.cpu) + " " + L.middle + " " + L.memory + " " + percentage(server.memory), Font.mediumSystemFont(11), palette.muted);
    return widget;
  }

  const headline = widget.addStack();
  headline.layoutHorizontally();
  text(headline, L.pin + " " + value(weather.city, L.city), Font.semiboldSystemFont(12), palette.muted);
  headline.addSpacer();
  text(headline, L.bullet + " " + (server.fresh ? L.normal : L.collecting), Font.semiboldSystemFont(11), server.fresh ? palette.good : palette.muted, "right");
  widget.addSpacer(6);

  if (family === "small") {
    const now = widget.addStack();
    now.layoutHorizontally();
    text(now, value(weather.emoji, L.cloud), Font.systemFont(35), palette.text);
    now.addSpacer(8);
    const temperature = now.addStack();
    temperature.layoutVertically();
    text(temperature, value(weather.temperature, "--") + L.degree, Font.boldSystemFont(38), palette.text);
    text(temperature, value(weather.condition, "--"), Font.mediumSystemFont(12), palette.muted);
    widget.addSpacer(8);
    text(widget, "CPU " + percentage(server.cpu) + "  " + L.middle + "  " + L.memory + " " + percentage(server.memory), Font.semiboldSystemFont(12), palette.text);
    text(widget, "Docker " + value(server.docker && server.docker.count, "--") + " " + L.unit + " " + L.middle + " " + value(server.updated, "--"), Font.mediumSystemFont(11), palette.muted);
    return widget;
  }

  const body = widget.addStack();
  body.layoutHorizontally();
  const left = body.addStack();
  left.layoutVertically();
  text(left, value(weather.emoji, L.cloud) + "  " + value(weather.temperature, "--") + L.degree, Font.boldSystemFont(34), palette.text);
  text(left, value(weather.condition, "--") + "  " + value(weather.high, "--") + L.degree + " / " + value(weather.low, "--") + L.degree, Font.mediumSystemFont(12), palette.muted);
  left.addSpacer(5);
  text(left, L.humidity + " " + value(weather.humidity, "--") + "%  " + L.middle + "  " + L.wind + " " + value(weather.wind_level, "--") + L.level, Font.mediumSystemFont(11), palette.text);
  text(left, L.air + " " + value(weather.aqi_label, "--") + " " + value(weather.aqi, "--") + "  " + L.middle + "  " + L.uv + " " + value(weather.uv_level, "--"), Font.mediumSystemFont(11), palette.text);
  body.addSpacer(16);
  const right = body.addStack();
  right.layoutVertically();
  metricLine(right, "CPU", server.cpu, palette);
  right.addSpacer(3);
  metricLine(right, L.memory, server.memory, palette);
  right.addSpacer(3);
  metricLine(right, L.disk, server.disk, palette);
  right.addSpacer(3);
  metricLine(right, "Docker", {label: value(server.docker && server.docker.count, "--") + " " + L.unit}, palette);

  if (family === "large") {
    widget.addSpacer(10);
    const forecast = weather.forecast || [];
    const forecastLine = forecast.map(function (item) {
      return item.label + " " + item.emoji + " " + value(item.high, "--") + L.degree + "/" + value(item.low, "--") + L.degree;
    }).join("  " + L.middle + "  ");
    text(widget, forecastLine || L.futureWaiting, Font.mediumSystemFont(12), palette.text);
    text(widget, value(weather.advice, L.calmAdvice), Font.mediumSystemFont(11), palette.muted);
    widget.addSpacer(9);
    text(widget, L.aiBalance, Font.semiboldSystemFont(13), palette.text);
    text(widget, accountText(data.accounts), Font.mediumSystemFont(11), palette.muted);
    const containers = server.docker && server.docker.containers ? server.docker.containers : [];
    for (const item of containers) {
      text(widget, (item.running ? L.bullet + " " : L.emptyBullet + " ") + item.name + "  " + item.status, Font.mediumSystemFont(11), item.running ? palette.good : palette.muted);
    }
  } else {
    widget.addSpacer(7);
    text(widget, accountText(data.accounts), Font.mediumSystemFont(11), palette.muted);
  }
  widget.addSpacer();
  text(widget, L.updated + value(server.updated, "--") + " " + L.middle + " " + L.about + refreshMinutes + L.minuteRefresh, Font.mediumSystemFont(10), palette.muted);
  return widget;
}

async function placeholder(message) {
  const widget = new ListWidget();
  if (hasTransparentBackground()) widget.backgroundImage = transparentBackground();
  else widget.backgroundColor = new Color("172847");
  widget.setPadding(16, 16, 16, 16);
  text(widget, L.title, Font.boldSystemFont(16), Color.white());
  widget.addSpacer(8);
  text(widget, message, Font.mediumSystemFont(12), hasTransparentBackground() ? Color.white() : new Color("D1E5FF"));
  return widget;
}

let openUrl = await viewerUrl();
if (openUrl && !config.runsInWidget) openUrl = await appMenu(openUrl);
let widget;
if (!openUrl) {
  widget = await placeholder(L.initial);
} else {
  try {
    widget = makeWidget(await loadData(openUrl), openUrl);
  } catch (error) {
    widget = await placeholder(L.unavailable + error.message);
    widget.url = openUrl;
  }
}

Script.setWidget(widget);
if (!config.runsInWidget) await widget.presentMedium();
Script.complete();
