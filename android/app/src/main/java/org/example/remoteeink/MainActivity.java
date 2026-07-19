package org.example.remoteeink;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.WallpaperManager;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.SharedPreferences;
import android.content.res.Configuration;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.os.Build;
import android.os.Environment;
import android.os.AsyncTask;
import android.os.Bundle;
import android.os.Handler;
import android.view.Gravity;
import android.view.View;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.TextView;

import java.io.File;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;

/**
 * Android 4.2-compatible static image client.
 * Long-press the image to set its private HTTPS frame URL and refresh interval.
 */
public final class MainActivity extends Activity {
    private static final String PREFS = "dashboard";
    private static final String KEY_URL = "frame_url";
    private static final String KEY_LANDSCAPE_URL = "landscape_url";
    private static final String KEY_PORTRAIT_URL = "portrait_url";
    private static final String KEY_INTERVAL = "interval_seconds";
    private static final String LEGACY_CACHE_FILE = "last-frame.png";
    private static final String LANDSCAPE_CACHE_FILE = "last-frame-landscape.png";
    private static final String PORTRAIT_CACHE_FILE = "last-frame-portrait.png";
    private static final String WALLPAPER_DIRECTORY = "wallpaper";
    private static final String WALLPAPER_FILE = "remote-eink-dashboard.png";
    private static final int DEFAULT_INTERVAL_SECONDS = 900;

    private final Handler handler = new Handler();
    private ImageView frame;
    private TextView status;
    private SharedPreferences prefs;
    private final Runnable poll = new Runnable() {
        @Override public void run() {
            refresh();
        }
    };

    @Override public void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        fullscreen();
        prefs = getSharedPreferences(PREFS, Context.MODE_PRIVATE);
        migrateLegacySettings();
        applyIntentSettings(getIntent());

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(0xFFFFFFFF);
        frame = new ImageView(this);
        frame.setBackgroundColor(0xFFFFFFFF);
        frame.setScaleType(ImageView.ScaleType.FIT_CENTER);
        root.addView(frame, new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT));

        status = new TextView(this);
        status.setTextColor(0xFF555555);
        status.setTextSize(12);
        status.setPadding(10, 6, 10, 6);
        FrameLayout.LayoutParams statusLayout = new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.WRAP_CONTENT, FrameLayout.LayoutParams.WRAP_CONTENT,
                Gravity.BOTTOM | Gravity.RIGHT);
        root.addView(status, statusLayout);
        setContentView(root);
        loadCachedFrame();

        frame.setOnLongClickListener(new View.OnLongClickListener() {
            @Override public boolean onLongClick(View view) {
                showSettings();
                return true;
            }
        });
    }

    @Override protected void onResume() {
        super.onResume();
        fullscreen();
        refresh();
    }

    @Override protected void onPause() {
        super.onPause();
        handler.removeCallbacks(poll);
    }

    @Override public void onConfigurationChanged(Configuration newConfig) {
        super.onConfigurationChanged(newConfig);
        fullscreen();
        handler.removeCallbacks(poll);
        loadCachedFrame();
        refresh();
    }

    private void fullscreen() {
        getWindow().getDecorView().setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_FULLSCREEN | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY);
    }

    private void refresh() {
        handler.removeCallbacks(poll);
        int orientation = currentOrientation();
        String url = prefs.getString(urlKey(orientation), "").trim();
        if (url.isEmpty()) {
            status.setText("长按画面，设置横屏和竖屏地址");
            return;
        }
        new DownloadFrame(orientation, cacheFile(orientation)).execute(url);
    }

    private int intervalMillis() {
        int seconds = prefs.getInt(KEY_INTERVAL, DEFAULT_INTERVAL_SECONDS);
        return Math.max(60, seconds) * 1000;
    }

    private void loadCachedFrame() {
        int orientation = currentOrientation();
        File cache = getFileStreamPath(cacheFile(orientation));
        if (!cache.isFile() && orientation == Configuration.ORIENTATION_LANDSCAPE) {
            cache = getFileStreamPath(LEGACY_CACHE_FILE);
        }
        if (!cache.isFile()) return;
        Bitmap bitmap = BitmapFactory.decodeFile(cache.getAbsolutePath());
        if (bitmap != null) {
            frame.setImageBitmap(bitmap);
            saveWallpaper(bitmap);
        }
    }

    private void saveCachedFrame(Bitmap bitmap, String cacheFile) {
        try (FileOutputStream output = openFileOutput(cacheFile, Context.MODE_PRIVATE)) {
            bitmap.compress(Bitmap.CompressFormat.PNG, 100, output);
        } catch (Exception ignored) { }
    }

    private void saveWallpaper(Bitmap bitmap) {
        try {
            File directory = new File(Environment.getExternalStorageDirectory(), WALLPAPER_DIRECTORY);
            if (directory.isDirectory() || directory.mkdirs()) {
                try (FileOutputStream output = new FileOutputStream(new File(directory, WALLPAPER_FILE))) {
                    bitmap.compress(Bitmap.CompressFormat.PNG, 100, output);
                }
            }
        } catch (Exception ignored) { }
        if (shouldSetSystemWallpaper()) {
            try {
                WallpaperManager.getInstance(this).setBitmap(bitmap);
            } catch (Exception ignored) { }
        }
    }

    private boolean shouldSetSystemWallpaper() {
        return "IMX6SL".equalsIgnoreCase(Build.MODEL)
                || "kindlemod".equalsIgnoreCase(Build.DEVICE);
    }

    private int currentOrientation() {
        return getResources().getConfiguration().orientation == Configuration.ORIENTATION_PORTRAIT
                ? Configuration.ORIENTATION_PORTRAIT : Configuration.ORIENTATION_LANDSCAPE;
    }

    private String urlKey(int orientation) {
        return orientation == Configuration.ORIENTATION_PORTRAIT ? KEY_PORTRAIT_URL : KEY_LANDSCAPE_URL;
    }

    private String cacheFile(int orientation) {
        return orientation == Configuration.ORIENTATION_PORTRAIT
                ? PORTRAIT_CACHE_FILE : LANDSCAPE_CACHE_FILE;
    }

    private void migrateLegacySettings() {
        if (prefs.contains(KEY_LANDSCAPE_URL) && prefs.contains(KEY_PORTRAIT_URL)) return;
        String legacyUrl = prefs.getString(KEY_URL, "").trim();
        if (legacyUrl.isEmpty()) return;
        SharedPreferences.Editor editor = prefs.edit();
        if (!prefs.contains(KEY_LANDSCAPE_URL)) editor.putString(KEY_LANDSCAPE_URL, legacyUrl);
        if (!prefs.contains(KEY_PORTRAIT_URL)) editor.putString(KEY_PORTRAIT_URL, legacyUrl);
        editor.apply();
    }

    private void applyIntentSettings(Intent intent) {
        if (intent == null) return;
        String configuredUrl = intent.getStringExtra("frame_url");
        String landscapeUrl = intent.getStringExtra("landscape_url");
        String portraitUrl = intent.getStringExtra("portrait_url");
        int configuredInterval = intent.getIntExtra("interval_seconds", -1);
        SharedPreferences.Editor editor = prefs.edit();
        boolean changed = false;
        if (configuredUrl != null && !configuredUrl.trim().isEmpty()) {
            editor.putString(KEY_LANDSCAPE_URL, configuredUrl.trim());
            changed = true;
        }
        if (landscapeUrl != null && !landscapeUrl.trim().isEmpty()) {
            editor.putString(KEY_LANDSCAPE_URL, landscapeUrl.trim());
            changed = true;
        }
        if (portraitUrl != null && !portraitUrl.trim().isEmpty()) {
            editor.putString(KEY_PORTRAIT_URL, portraitUrl.trim());
            changed = true;
        }
        if (configuredInterval >= 60) {
            editor.putInt(KEY_INTERVAL, configuredInterval);
            changed = true;
        }
        if (changed) editor.apply();
    }

    private int batteryLevel() {
        Intent status = registerReceiver(null, new IntentFilter(Intent.ACTION_BATTERY_CHANGED));
        if (status == null) return -1;
        int level = status.getIntExtra("level", -1);
        int scale = status.getIntExtra("scale", -1);
        if (level < 0 || scale <= 0) return -1;
        return Math.max(0, Math.min(100, Math.round(level * 100f / scale)));
    }

    private void showSettings() {
        final EditText landscapeUrl = new EditText(this);
        landscapeUrl.setSingleLine(true);
        landscapeUrl.setHint("横屏帧地址");
        landscapeUrl.setText(prefs.getString(KEY_LANDSCAPE_URL, ""));

        final EditText portraitUrl = new EditText(this);
        portraitUrl.setSingleLine(true);
        portraitUrl.setHint("竖屏帧地址");
        portraitUrl.setText(prefs.getString(KEY_PORTRAIT_URL, ""));

        final EditText interval = new EditText(this);
        interval.setSingleLine(true);
        interval.setInputType(android.text.InputType.TYPE_CLASS_NUMBER);
        interval.setHint("刷新秒数（建议 900）");
        interval.setText(String.valueOf(prefs.getInt(KEY_INTERVAL, DEFAULT_INTERVAL_SECONDS)));

        FrameLayout form = new FrameLayout(this);
        form.setPadding(48, 20, 48, 20);
        form.addView(landscapeUrl, new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.WRAP_CONTENT));
        FrameLayout.LayoutParams portraitLayout = new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.WRAP_CONTENT);
        portraitLayout.topMargin = 88;
        form.addView(portraitUrl, portraitLayout);
        FrameLayout.LayoutParams intervalLayout = new FrameLayout.LayoutParams(
                FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.WRAP_CONTENT);
        intervalLayout.topMargin = 176;
        form.addView(interval, intervalLayout);

        new AlertDialog.Builder(this)
                .setTitle("墨水看板设置")
                .setView(form)
                .setNegativeButton("取消", null)
                .setPositiveButton("保存", (dialog, which) -> {
                    int seconds = DEFAULT_INTERVAL_SECONDS;
                    try { seconds = Integer.parseInt(interval.getText().toString()); } catch (NumberFormatException ignored) { }
                    prefs.edit().putString(KEY_LANDSCAPE_URL, landscapeUrl.getText().toString().trim())
                            .putString(KEY_PORTRAIT_URL, portraitUrl.getText().toString().trim())
                            .putInt(KEY_INTERVAL, Math.max(60, seconds)).apply();
                    loadCachedFrame();
                    refresh();
                })
                .show();
    }

    private final class DownloadFrame extends AsyncTask<String, Void, Bitmap> {
        private final int requestedOrientation;
        private final String requestedCacheFile;
        private String error = "";

        DownloadFrame(int requestedOrientation, String requestedCacheFile) {
            this.requestedOrientation = requestedOrientation;
            this.requestedCacheFile = requestedCacheFile;
        }

        @Override protected Bitmap doInBackground(String... urls) {
            HttpURLConnection connection = null;
            try {
                int battery = batteryLevel();
                String requestUrl = urls[0] + (urls[0].contains("?") ? "&" : "?")
                        + "ts=" + System.currentTimeMillis()
                        + (battery >= 0 ? "&battery=" + battery : "");
                connection = (HttpURLConnection) new URL(requestUrl).openConnection();
                connection.setConnectTimeout(15000);
                connection.setReadTimeout(30000);
                connection.setUseCaches(false);
                connection.setRequestProperty("Cache-Control", "no-cache");
                if (connection.getResponseCode() != HttpURLConnection.HTTP_OK) {
                    error = "服务返回 " + connection.getResponseCode();
                    return null;
                }
                InputStream input = connection.getInputStream();
                try {
                    Bitmap bitmap = BitmapFactory.decodeStream(input);
                    if (bitmap != null) {
                        saveCachedFrame(bitmap, requestedCacheFile);
                        saveWallpaper(bitmap);
                    }
                    return bitmap;
                } finally { input.close(); }
            } catch (Exception exception) {
                error = "拉图失败";
                return null;
            } finally {
                if (connection != null) connection.disconnect();
            }
        }

        @Override protected void onPostExecute(Bitmap bitmap) {
            if (currentOrientation() != requestedOrientation) return;
            if (bitmap != null) {
                frame.setImageBitmap(bitmap);
                status.setText("");
            } else {
                status.setText(frame.getDrawable() == null ? error : "");
            }
            handler.postDelayed(poll, intervalMillis());
        }
    }
}
