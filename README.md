# WP One-Click Backup & Restore | WP 一键备份还原

<p align="center">
  <a href="#english">English</a> | <a href="#简体中文">简体中文</a>
</p>

---

<a name="english"></a>

## 🌐 English

**WP One-Click Backup & Restore** is a high-performance WordPress plugin designed for seamless site migration and data security. It handles large files through dynamic chunking and ensures database integrity with serialization-aware replacement.

### ✨ Key Features

- **🚀 Dynamic Chunked Uploads**: 
  - Auto-adjust chunk size (2MB-10MB) based on network.
  - Breakpoint resumable uploads (no need to restart on failure).
  - Exponential backoff retry for high stability.
- **🛡️ Serialization-Safe Replacement**: 
  - Automatically handles serialized data during domain 
    migration to prevent theme/plugin settings corruption.
- **💾 Streaming DB Engine**: 
  - Exports and imports databases using streaming to bypass 
    PHP memory limits and execution timeouts.
- **🔍 Intelligent Pre-checks**: 
  - Real-time disk space estimation.
  - ZIP64 compatibility alerts for files exceeding 4GB.
- **🔑 Session Persistence**: 
  - Keeps you logged in after restoration by preserving 
    active session tokens.

### 🛠️ Requirements

- **PHP**: 7.0+ (7.4+ recommended).
- **WordPress**: 5.0+.
- **Extension**: `ZipArchive` (libzip 1.6.0+ for 4GB+ files).

### 🚀 Quick Start

1. Download and upload to `/wp-content/plugins/`.
2. Activate via WordPress dashboard.
3. Go to **Tools > WP Backup Restore**.
4. **Backup**: Click "Backup Now".
5. **Restore**: Select a `.bgbk` file and click "Full Restore".

---

<a name="简体中文"></a>

## 🇨🇳 简体中文

**WP一键备份还原** 是一款高性能 WordPress 插件，专为网站迁移和数据安全而生。它支持动态分片技术处理大文件，并通过兼容序列化的域名替换技术确保数据的绝对安全。

### ✨ 核心特性

- **🚀 动态分片上传**:
  - 根据网络环境自动调整分片（2MB - 10MB）。
  - 支持断点续传，无需因网络波动从头开始。
  - 内置指数退避重试机制，极高提高成功率。
- **🛡️ 序列化安全替换**:
  - 还原时自动处理数据库中的序列化字符串，
    防止主题和插件配置在更换域名后失效。
- **💾 流式数据库引擎**:
  - 采用流式导出/导入技术，彻底告别 
    PHP 内存溢出和执行超时问题。
- **🔍 智能预检查**:
  - 备份/还原前自动估算所需磁盘空间。
  - 针对 4GB 以上大文件提供 ZIP64 风险提示。
- **🔑 登录状态保持**:
  - 还原后自动迁移 Session，无需重新登录即可继续操作。

### 🛠️ 技术要求

- **PHP 版本**: 7.0 及以上 (推荐 7.4+)。
- **WordPress 版本**: 5.0 及以上。
- **必要扩展**: `ZipArchive` (处理 4GB+ 文件建议 libzip 1.6.0+)。

### 🚀 快速上手

1. 下载本插件并上传至 `/wp-content/plugins/`。
2. 在 WordPress 后台启用插件。
3. 进入 **工具 > WP一键备份还原**。
4. **备份**: 点击“立即备份”。
5. **还原**: 选择或上传 `.bgbk` 文件，点击“全站一键还原”。

---

<div style="text-align: left;">
  <img src="https://github.com/user-attachments/assets/dd821a5f-9959-4247-89eb-6f6f25bf98d1" alt="WP 一键备份还原" width="100%">
</div>

---

<div style="text-align: left;">
  <img src="https://github.com/user-attachments/assets/01ab90d6-c6d5-4e91-85e1-dbff0eaabe71" alt="磁盘检查" width="40%">
</div>
<div style="text-align: left;">
  <img src="https://github.com/user-attachments/assets/d81d961f-deac-4361-a88c-ad906bbc55d2" alt="备份全站" width="40%">
</div>

---

<div style="text-align: left;">
  <img src="https://github.com/user-attachments/assets/e2636924-9440-4df3-80ab-2b6eeb2beee3" alt="还原全站" width="40%">
</div>

---


### 📄 License

Author: [Stone](https://blog.cacca.cc)  
This project is licensed under the GPLv2 or later.

