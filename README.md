# WP一键备份还原 ⚡

[![Version](https://img.shields.io/badge/Version-1.0.3-brightgreen)](https://github.com/guoshh1978/WordPress-OneKey-Backup-Restore)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-blue)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-brightgreen)](https://wordpress.org/)
[![License](https://img.shields.io/badge/License-GPL%20v2-orange)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

这是一个专为**大数据量站点**设计的 WordPress 备份与还原工具。它直接调用系统级能力，支持动态分片上传与原子级数据库切换，追求极致的执行速度与还原成功率。
<div style="text-align: left;">
  <img src="https://github.com/user-attachments/assets/dd821a5f-9959-4247-89eb-6f6f25bf98d1" alt="WP 一键备份还原" width="80%">
</div>

---

## 🛠️ 安装说明

### 1. 下载与上传
- 下载本项目仓库的 ZIP 压缩包。
- 将解压后的文件夹 `wp-backup-restore` 上传至 WordPress 站点的 `/wp-content/plugins/` 目录。
- *或者：在 WordPress 后台「插件」→「安装插件」→「上传插件」，选择 ZIP 包安装。*

### 2. 启用插件
- 在 WordPress 后台「插件」页面，找到 **“WP一键备份还原”**，点击 **「启用」**。

### 3. 环境配置 (重要)
为了确保大数据量处理的绝对稳定，请确保服务器已开放相关权限并调整参数：

- **函数支持**：确保 PHP 未禁用 `exec` 和 `popen` 函数。
- **目录权限**：确保 `/wp-content/uploads/` 目录具有写入权限（插件会自动创建 `wpbkres` 备份目录）。
- **PHP 参数调整 (`php.ini`)**：
  ```ini
  upload_max_filesize = 512M
  post_max_size = 512M
  memory_limit = 512M
  max_execution_time = 0
  ```
- **Nginx 参数调整 (`nginx.conf`)**：
  ```nginx
  client_max_body_size 512M;
  ```

---

## 🚀 使用指南

进入路径：**WordPress 后台 -> 工具 -> WP备份还原**

### 🔹 备份全站
1. 点击 **「立即备份」**。
2. 系统将弹出“磁盘空间检查”窗口，自动计算站点文件和数据库总大小。
3. 点击 **「继续」**，系统将在后台执行：扫描文件 -> 打包压缩 -> 流式导出数据库 -> 生成 `.bgbk` 备份包。
4. 完成后，备份包会显示在下拉列表中。
<div style="text-align: left;">
  <img src="https://github.com/user-attachments/assets/01ab90d6-c6d5-4e91-85e1-dbff0eaabe71" alt="磁盘检查" width="40%">
</div>
<div style="text-align: left;">
  <img src="https://github.com/user-attachments/assets/d81d961f-deac-4361-a88c-ad906bbc55d2" alt="备份全站" width="40%">
</div>


### 🔹 上传外部备份
1. 点击 **「上传备份」**，选择本地的 `.bgbk` 文件。
2. 插件会自动开启**动态分片上传**。如果由于网络波动中断，只需再次点击上传并选择同一文件，系统会自动实现**断点续传**。
3. 上传完成后，文件将出现在还原下拉列表中。
<div style="text-align: left;">
  <img src="https://github.com/user-attachments/assets/e5f5e394-17e3-4619-befb-bea738d49b21" alt="上传备份" width="40%">
</div>

### 🔹 还原全站 (迁移)
1. 从下拉列表中选择目标备份文件。
2. 点击 **「全站还原」**。
3. **空间预检**：系统会计算解压所需空间并检查 ZIP64 兼容性，确保环境安全。
4. **二次确认**：确认还原操作（注意：这将覆盖当前全站数据）。
5. **执行还原**：系统会依次执行 解压覆盖 -> 临时表导入 -> 原子切换 -> 域名替换 -> Session 恢复。
6. **刷新页面**：完成后点击确定并刷新页面。由于数据库已更换，您可能需要重新登录。
<div style="text-align: left;">
  <img src="https://github.com/user-attachments/assets/62d5cd56-a4aa-435c-b5e5-69ea19ca7ee6" alt="还原全站" width="40%">
</div>

---

## ✨ v1.0.3 核心特性

- **🔍 智能预检机制**：自动计算磁盘空间需求，预留 20% 冗余，防止还原到一半磁盘溢出。
- **🚀 动态分片上传**：512KB - 10MB 自动调节，应对弱网环境表现极佳，支持断点续传。
- **🔄 原子级表切换**：先导入临时表再执行 RENAME，即便还原过程意外中断，原站点数据依然不受影响。
- **🧠 深度序列化处理**：完美处理 WP 复杂的序列化域名替换，保证插件配置、菜单链接 100% 正确。
- **👤 Session 保持**：还原后自动同步登录 Token，同域名下迁移无需重新登录。

---

## 📊 还原进度说明

| 阶段 | 进度权重 | 关键操作 |
| :--- | :--- | :--- |
| **预检阶段** | 0% - 10% | 空间计算、ZIP64 检测、权限核验 |
| **解压阶段** | 10% - 45% | 系统级解压，原地覆盖现有文件 |
| **数据库导入** | 45% - 85% | 临时表批量导入（不影响原站点运行） |
| **原子切换** | 85% - 100% | 重命名数据表、域名递归替换、Session 重建 |

---

## 📁 目录与安全
- **备份位置**：`wp-content/uploads/wpbkres/`
- **安全加固**：内置 `.htaccess` 禁止 Web 直接访问备份包。
- **日志监控**：支持 5 级日志调试，通过以下命令实时查看进度：
```bash
tail -f wp-content/uploads/wpbkres/restore.log
```

---

## 🛠️ 故障排查
1. **进度条卡在 0%**：请检查浏览器控制台是否有 AJAX 报错，确认 `exec` 函数是否被 PHP 禁用。
2. **空间报错**：还原需要约 2.5 倍的剩余空间（备份包体积 + 解压后的体积 + 数据库临时表空间）。
3. **404 错误**：还原完成后，若内页打不开，请前往「设置 -> 固定链接」点击一次「保存更改」以重置路由。

---

## 📄 许可证
GPL v2 or later
