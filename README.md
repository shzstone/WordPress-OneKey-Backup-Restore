# WP一键备份还原 ⚡

[![PHP](https://img.shields.io/badge/PHP-7.2%2B-blue)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-brightgreen)](https://wordpress.org/)
[![License](https://img.shields.io/badge/License-GPL%20v2-orange)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

WP一键备份还原是一款高性能的 WordPress 备份与还原插件，支持异步后台处理、分步还原、自动域名替换，并提供实时进度反馈。  
特别适合大数据量站点（数万文件、百万级数据）的迁移与恢复，确保数据完整性与操作流畅性。

---

## ✨ 主要特性

| 功能 | 说明 |
| :--- | :--- |
| **一键备份** | 自动打包网站所有文件与数据库，生成独立的 `.bgbk` 备份文件 |
| **一键还原** | 从备份文件快速还原，自动覆盖现有文件与数据 |
| **异步后台处理** | 备份/还原任务在后台 Worker 进程执行，不阻塞用户操作 |
| **分步还原** | 支持分步还原策略，避免 PHP 执行超时（适用于大站点） |
| **自动域名替换** | 还原时自动将备份中的旧域名替换为当前站点域名（支持序列化数据） |
| **实时进度** | 前端模态窗显示备份与还原进度，同时保留详细日志便于排查 |
| **Session 保持** | 还原后自动恢复当前用户的登录状态（同域名下 100% 不掉线） |
| **安全存储** | 备份文件存放在 `wp-content/uploads/wpbkres/`，自动生成 `.htaccess` 阻止直接访问 |
| **调试日志** | 内置日志级别开关（OFF/ERROR/WARNING/INFO/DEBUG），便于问题排查 |

---

## 📦 安装

1. 下载插件源码，将整个文件夹上传至 WordPress 的 `/wp-content/plugins/` 目录。
2. 在 WordPress 后台「插件」页面找到“WP一键备份还原”，点击「启用」。
3. 进入「工具」→「WP备份还原」即可使用。

---

## 🚀 使用方法

### 🔹 备份
1. 进入「工具」→「WP备份还原」。
2. 点击 **「开始高性能全站备份」** 按钮。
3. 插件自动扫描文件并打包，模态窗实时显示进度。
4. 备份完成后，备份文件会出现在下拉列表中。

### 🔹 还原
1. 从下拉列表中选择需要还原的备份文件。
2. 点击 **「开始高性能全站还原」** 按钮，二次确认后开始执行。
3. 还原过程依次执行：
   - 解压文件
   - 导入数据库
   - 替换域名
   - 恢复登录状态
4. 还原完成后，点击「确定并手动刷新页面」按钮，根据提示可能需要重新登录一次。

### 🔹 调试日志
- 在插件页面底部选择日志级别（OFF/ERROR/WARNING/INFO/DEBUG）。
- 日志文件位置：`wp-content/uploads/wpbkres/restore.log`
- 可通过命令查看实时动态：
```bash
tail -f wp-content/uploads/wpbkres/restore.log
```

---

## 📊 进度与日志对照

| 阶段 | 前端显示 | 日志内容示例 |
| :--- | :--- | :--- |
| **解压** | 10% – 40% | 解压文件进度，共 x 个文件 |
| **导入** | 45% – 85% | SQL 文件总语句数、当前执行进度 |
| **域名替换** | 85% – 90% | 更新 siteurl/home 影响行数，替换文章内容 |
| **完成** | 100% | 还原流程全部完成 |

> **提示：** 对于超大站点，前端进度可能因网络延迟更新缓慢，建议以日志为准。

---

## ⚠️ 注意事项

*   **备份文件安全**：备份目录默认通过 `.htaccess` 禁止 Web 访问，请勿随意修改权限。
*   **执行时间与内存**：建议在 PHP 配置中提高以下参数：
```ini
max_execution_time = 0
memory_limit = 512M
```
*   **数据库超时**：如出现 `MySQL server has gone away`，可在 MySQL 配置中增大：
```ini
max_allowed_packet = 256M
wait_timeout = 600
```
*   **域名替换**：自动将备份中的 `siteurl` 和 `home` 替换为当前域名，并递归处理序列化数据。
*   **登录状态**：同域名下还原后自动保持登录，跨域名需重新登录。
*   **菜单丢失**：如导航菜单消失，请进入「外观 → 菜单 → 管理位置」重新分配菜单。

---

## 🛠️ 常见问题

**Q：还原时进度条卡在 0% 怎么办？**  
A：请检查浏览器控制台是否有 AJAX 错误，或查看 PHP 错误日志。如果日志显示正在解压或导入，说明任务正常执行，前端可能未及时更新，请以日志为准。

**Q：导入时出现 “MySQL server has gone away”？**  
A：SQL 文件过大或超时时间过短，尝试增大 `max_allowed_packet` 和 `wait_timeout`，或调低插件代码中的 `COMMIT_INTERVAL` 值。

**Q：备份时提示“无法创建临时目录”？**  
A：请检查 `wp-content/uploads/` 是否可写，或手动创建 `wpbkres` 目录并设置 755 权限。

**Q：还原后网站出现 404 错误？**  
A：请到「设置 → 固定链接」点击保存，重新生成 `.htaccess` 文件。

**Q：如何查看详细执行日志？**  
A：日志级别设为 `DEBUG`，执行还原后查看 `wp-content/uploads/wpbkres/restore.log`，包含每一步 SQL 执行及影响行数。

---

## 📁 文件结构

```text
wp-backup-restore/
├── WP-Res.php           # 主插件文件（核心逻辑）
├── restore-api.php      # 独立 API 入口（进度查询、任务启动、停止）
├── restore-worker.php   # 后台工作进程（执行备份/还原任务）
└── README.md            # 说明文档
```

**备份文件存储位置：**
```text
wp-content/uploads/wpbkres/
├── backup_*.bgbk        # 备份文件
├── state_*.json         # 任务状态文件（自动清理）
└── restore.log          # 调试日志
```

---

## 🔧 技术架构

-   **前后端分离**：API 与 Worker 分离，支持 CLI 模式执行。
-   **事务控制**：数据库导入按批提交，避免大事务超时。
-   **序列化兼容**：域名替换递归处理 PHP 序列化数据，确保配置不损坏。
-   **Session 保持**：通过 SHA256 哈希匹配，自动恢复登录状态。

---

## 🤝 贡献与反馈

欢迎提交 Issue 或 Pull Request 来帮助改进插件。

项目地址：[https://github.com/guoshh1978/WordPress-OneKey-Backup-Restore](https://github.com/guoshh1978/WordPress-OneKey-Backup-Restore)

---

## 📄 许可证

GPL v2 or later
