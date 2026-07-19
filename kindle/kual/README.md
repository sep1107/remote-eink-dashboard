# Kindle 安装内容

将本目录整体复制到 Kindle 的 `extensions/remote-eink-dashboard/`，再把 `eink-dashboard.conf.example` 改名为 `eink-dashboard.conf`，填入本设备专属帧地址。KUAL 菜单中选择 Start 即开始每十五分钟拉取一次图片，并在取图时上报设备电量。

KPW3 使用 `/frame/kpw3/<token>.png`；Oasis 1 使用 `/frame/oasis1/<token>.png`。必须等 HTTPS 可用后再安装。
