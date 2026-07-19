// Public calendar and weather widget for Scriptable. Version 26.
// Designed for small date, medium calendar, and large weather widgets on iPhone 13 mini and iPhone 15.

const API = "https://dashboard.example.com/calendar-weather.json";
const OPEN_URL = "https://dashboard.example.com";
const REFRESH_MINUTES = 30;
const CITIES = ["北京", "上海", "广州", "深圳", "成都", "杭州", "武汉", "西安"];
const L = {
  title: "\u65e5\u5386\u5929\u6c14",
  city: "\u5317\u4eac",
  humidity: "\u6e7f\u5ea6",
  wind: "\u98ce\u529b",
  air: "\u7a7a\u6c14",
  uv: "\u7d2b\u5916\u7ebf",
  sunrise: "\u65e5\u51fa",
  sunset: "\u65e5\u843d",
  update: "\u66f4\u65b0\u4e8e: ",
  refresh: " \u5206\u949f\u5237\u65b0",
  unavailable: "\u6682\u65f6\u65e0\u6cd5\u83b7\u53d6\u5929\u6c14\u65e5\u5386\u3002",
  unsupportedSize: "\u8bf7\u4f7f\u7528\u5c0f\u53f7\u3001\u4e2d\u53f7\u6216\u5927\u53f7\u5c0f\u7ec4\u4ef6\u3002"
};

const palette = {
  background: new Color("FFFFFF"),
  panel: new Color("F2F5F8"),
  text: new Color("16253E"),
  muted: new Color("53657C"),
  quiet: new Color("78889C"),
  accent: new Color("297C91")
};

function text(target, value, font, color, alignment) {
  const item = target.addText(String(value));
  item.font = font;
  item.textColor = color;
  item.lineLimit = 1;
  item.minimumScaleFactor = 0.6;
  if (alignment === "center") item.centerAlignText();
  if (alignment === "right") item.rightAlignText();
  return item;
}

function value(input, fallback) {
  return input === undefined || input === null || input === "" ? fallback : input;
}

function selectedCity() {
  const city = String(args.widgetParameter || "").trim().replace(/市$/, "");
  return CITIES.includes(city) ? city : "北京";
}

function updateLabel(timestamp) {
  const date = new Date(timestamp || Date.now());
  const day = date.getFullYear() + "\u5e74" + (date.getMonth() + 1) + "\u6708" + date.getDate() + "\u65e5";
  const time = date.toLocaleTimeString([], {hour: "2-digit", minute: "2-digit", hour12: false});
  return L.update + day + " " + time;
}

function centeredLine(target, width, value, font, color, inset = 0) {
  const line = target.addStack();
  line.size = new Size(width, 0);
  line.layoutHorizontally();
  if (inset) line.addSpacer(inset);
  const content = line.addStack();
  content.size = new Size(width - inset * 2, 0);
  content.layoutHorizontally();
  content.centerAlignContent();
  const item = text(content, value, font, color, "center");
  if (inset) line.addSpacer(inset);
  return item;
}

function forecastRow(target, forecast) {
  const row = target.addStack();
  row.layoutHorizontally();
  row.centerAlignContent();
  const days = forecast.slice(0, 4);
  for (let index = 0; index < days.length; index += 1) {
    const item = days[index];
    const card = row.addStack();
    card.size = new Size(76, 128);
    card.layoutVertically();
    card.centerAlignContent();
    card.backgroundColor = palette.panel;
    card.cornerRadius = 11;
    centeredLine(card, 76, value(item.label, "--"), Font.semiboldSystemFont(15), palette.muted, 4);
    centeredLine(card, 76, value(item.emoji, "\u2601\ufe0f"), Font.systemFont(30), palette.text, 4);
    centeredLine(card, 76, value(item.condition, "--"), Font.semiboldSystemFont(14), palette.text, 4);
    centeredLine(card, 76, value(item.low, "--") + "\u00b0 / " + value(item.high, "--") + "\u00b0", Font.semiboldSystemFont(14), palette.text, 4);
    const rain = value(item.rain_probability, "--");
    const wind = value(item.wind_level, "--");
    centeredLine(card, 76, "\u964d\u96e8 " + (rain === "--" ? rain : rain + "%"), Font.mediumSystemFont(13), palette.quiet, 4);
    centeredLine(card, 76, "\u98ce\u529b " + (wind === "--" ? wind : wind + "\u7ea7"), Font.mediumSystemFont(13), palette.quiet, 4);
    if (index < days.length - 1) row.addSpacer();
  }
}

function airQualityColor(label) {
  if (label === "优") return new Color("15803D");
  if (label === "良") return new Color("5C8A3A");
  if (label === "轻度") return new Color("B7791F");
  if (label === "中度") return new Color("C05621");
  if (label === "重度") return new Color("C53030");
  if (label === "严重") return new Color("7F1D1D");
  return palette.text;
}

function uvColor(label) {
  if (label === "低") return new Color("A78BFA");
  if (label === "中等") return new Color("8B5CF6");
  if (label === "高") return new Color("7C3AED");
  if (label === "很高") return new Color("5B21B6");
  if (label === "极高") return new Color("4C1D3D");
  return palette.text;
}

function detailRow(target, label, number, numberColor = palette.text) {
  const row = target.addStack();
  row.size = new Size(110, 0);
  row.layoutHorizontally();
  row.centerAlignContent();
  const labelColumn = row.addStack();
  labelColumn.size = new Size(58, 0);
  labelColumn.layoutHorizontally();
  labelColumn.centerAlignContent();
  text(labelColumn, label, Font.mediumSystemFont(18), palette.quiet);
  const valueColumn = row.addStack();
  valueColumn.size = new Size(52, 0);
  valueColumn.layoutHorizontally();
  valueColumn.centerAlignContent();
  valueColumn.addSpacer();
  text(valueColumn, number, Font.semiboldSystemFont(22), numberColor, "right");
}

function centeredText(target, value, font, color) {
  target.addSpacer();
  text(target, value, font, color, "center");
  target.addSpacer();
}

function summaryLine(target, value, font, color) {
  const line = target.addStack();
  line.layoutHorizontally();
  centeredText(line, value, font, color);
}

function miniCalendarCell(row, cell) {
  const slot = row.addStack();
  slot.size = new Size(31, 22);
  slot.layoutVertically();
  slot.centerAlignContent();
  if (!cell) return;
  if (cell.today) {
    slot.backgroundColor = palette.panel;
    slot.cornerRadius = 6;
  }
  const day = slot.addStack();
  day.layoutHorizontally();
  centeredText(day, value(cell.day, ""), Font.semiboldSystemFont(13), palette.text);
  const lunar = slot.addStack();
  lunar.layoutHorizontally();
  centeredText(lunar, value(cell.lunar, ""), Font.systemFont(8), palette.quiet);
}

function makeSmallWidget(data) {
  const widget = new ListWidget();
  widget.backgroundColor = palette.background;
  widget.setPadding(4, 5, 4, 5);
  widget.url = OPEN_URL + "?city=" + encodeURIComponent(selectedCity());
  widget.refreshAfterDate = new Date(Date.now() + Math.max(5, Number(data.refresh_minutes || REFRESH_MINUTES)) * 60 * 1000);

  const calendar = data.calendar || {};
  const summary = widget.addStack();
  summary.size = new Size(145, 0);
  summary.layoutVertically();
  summary.centerAlignContent();
  summaryLine(summary, value(calendar.label, "--"), Font.semiboldSystemFont(20), palette.muted);
  summaryLine(summary, value(calendar.day, "--"), Font.boldSystemFont(70), palette.text);
  summaryLine(summary, "\u661f\u671f" + value(calendar.weekday, "--"), Font.semiboldSystemFont(20), palette.text);
  summaryLine(summary, value(calendar.lunar, "\u519c\u5386 --"), Font.mediumSystemFont(18), palette.quiet);
  return widget;
}

function makeMediumWidget(data) {
  const widget = new ListWidget();
  widget.backgroundColor = palette.background;
  widget.setPadding(4, 7, 4, 7);
  widget.url = OPEN_URL + "?city=" + encodeURIComponent(selectedCity());
  widget.refreshAfterDate = new Date(Date.now() + Math.max(5, Number(data.refresh_minutes || REFRESH_MINUTES)) * 60 * 1000);

  const calendar = data.calendar || {};
  const lunarMatch = String(value(calendar.lunar, "农历 --")).match(/^(.+?年)(.+)$/);
  const lunarYear = lunarMatch ? lunarMatch[1] : value(calendar.lunar, "农历 --");
  const lunarDate = lunarMatch ? lunarMatch[2] : "";
  const content = widget.addStack();
  content.layoutHorizontally();
  content.centerAlignContent();

  const summary = content.addStack();
  summary.size = new Size(94, 0);
  summary.layoutVertically();
  summary.centerAlignContent();
  summaryLine(summary, value(calendar.label, "--"), Font.semiboldSystemFont(15), palette.muted);
  summaryLine(summary, value(calendar.day, "--"), Font.boldSystemFont(60), palette.text);
  summaryLine(summary, "星期" + value(calendar.weekday, "--"), Font.semiboldSystemFont(16), palette.text);
  summaryLine(summary, lunarYear, Font.mediumSystemFont(12), palette.quiet);
  summaryLine(summary, lunarDate, Font.mediumSystemFont(13), palette.quiet);

  content.addSpacer(4);
  const calendarGrid = content.addStack();
  calendarGrid.size = new Size(217, 0);
  calendarGrid.layoutVertically();
  const week = calendarGrid.addStack();
  week.layoutHorizontally();
  for (const label of ["日", "一", "二", "三", "四", "五", "六"]) {
    const slot = week.addStack();
    slot.size = new Size(31, 13);
    slot.layoutHorizontally();
    centeredText(slot, label, Font.semiboldSystemFont(12), palette.quiet);
  }
  const cells = Array.isArray(calendar.cells) ? calendar.cells : [];
  for (let index = 0; index < cells.length; index += 7) {
    const row = calendarGrid.addStack();
    row.layoutHorizontally();
    for (let offset = 0; offset < 7; offset += 1) miniCalendarCell(row, cells[index + offset]);
  }
  return widget;
}

function makeWidget(data) {
  const widget = new ListWidget();
  widget.backgroundColor = palette.background;
  widget.setPadding(12, 12, 10, 12);
  widget.url = OPEN_URL + "?city=" + encodeURIComponent(selectedCity());
  widget.refreshAfterDate = new Date(Date.now() + Math.max(5, Number(data.refresh_minutes || REFRESH_MINUTES)) * 60 * 1000);

  const weather = data.weather || {};
  const headline = widget.addStack();
  headline.layoutHorizontally();
  text(headline, "\ud83d\udccd " + value(weather.city, L.city), Font.boldSystemFont(24), palette.text);
  headline.addSpacer();
  text(headline, updateLabel(data.generated_at), Font.mediumSystemFont(12), palette.quiet, "right");

  widget.addSpacer(5);
  const current = widget.addStack();
  current.layoutHorizontally();
  current.centerAlignContent();
  const weatherNow = current.addStack();
  weatherNow.size = new Size(78, 0);
  weatherNow.layoutVertically();
  weatherNow.centerAlignContent();
  centeredLine(weatherNow, 78, value(weather.emoji, "\u2601\ufe0f"), Font.systemFont(64), palette.text);
  centeredLine(weatherNow, 78, value(weather.condition, "--"), Font.semiboldSystemFont(20), palette.muted);
  current.addSpacer(7);
  const temperature = current.addStack();
  temperature.size = new Size(110, 0);
  temperature.layoutVertically();
  temperature.centerAlignContent();
  centeredLine(temperature, 110, value(weather.temperature, "--") + "\u00b0", Font.boldSystemFont(72), palette.text);
  centeredLine(temperature, 110, value(weather.low, "--") + "\u00b0 / " + value(weather.high, "--") + "\u00b0", Font.mediumSystemFont(22), palette.quiet);
  current.addSpacer();
  const details = current.addStack();
  details.size = new Size(110, 0);
  details.layoutVertically();
  details.centerAlignContent();
  detailRow(details, L.humidity, value(weather.humidity, "--") + "%");
  detailRow(details, L.wind, value(weather.wind_level, "--") + "\u7ea7");
  const air = value(weather.aqi_label, "--");
  const uv = value(weather.uv_level, "--");
  detailRow(details, L.air, air, airQualityColor(air));
  detailRow(details, L.uv, uv, uvColor(uv));
  current.addSpacer(10);

  widget.addSpacer(5);
  const sun = widget.addStack();
  sun.layoutHorizontally();
  text(sun, "\u2600 " + L.sunrise + " " + value(weather.sunrise, "--"), Font.mediumSystemFont(16), palette.muted);
  sun.addSpacer();
  text(sun, "\u263e " + L.sunset + " " + value(weather.sunset, "--"), Font.mediumSystemFont(16), palette.muted, "right");
  widget.addSpacer(5);
  const advice = text(widget, "\u2602 " + value(weather.advice, "--"), Font.mediumSystemFont(17), palette.accent);
  advice.lineLimit = 2;
  widget.addSpacer(5);
  forecastRow(widget, weather.forecast || []);
  widget.addSpacer();
  text(widget, "\u7ea6 " + value(data.refresh_minutes, REFRESH_MINUTES) + L.refresh, Font.systemFont(11), palette.quiet, "center");
  return widget;
}

function placeholder(message) {
  const widget = new ListWidget();
  widget.backgroundColor = palette.background;
  widget.setPadding(18, 18, 18, 18);
  text(widget, L.title, Font.boldSystemFont(18), palette.text);
  widget.addSpacer(8);
  text(widget, message, Font.mediumSystemFont(12), palette.muted);
  widget.url = OPEN_URL + "?city=" + encodeURIComponent(selectedCity());
  return widget;
}

let widget;
if (config.runsInWidget && !["small", "medium", "large"].includes(config.widgetFamily)) {
  widget = placeholder(L.unsupportedSize);
} else {
  try {
    const request = new Request(API + "?city=" + encodeURIComponent(selectedCity()));
    request.timeoutInterval = 10;
    const data = await request.loadJSON();
    if (config.widgetFamily === "small") widget = makeSmallWidget(data);
    else if (config.widgetFamily === "medium") widget = makeMediumWidget(data);
    else widget = makeWidget(data);
  } catch (error) {
    widget = placeholder(L.unavailable);
  }
}

Script.setWidget(widget);
if (!config.runsInWidget) await widget.presentLarge();
Script.complete();
