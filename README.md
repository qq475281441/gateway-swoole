# gateway-swoole
一个基于swoole开发的im服务器，支持集群搭建

- Db类使用的thinkphp5官方orm
- 使用网关注册子服务，支持不同协议间通信，如socket+websocket，子服务只需向网关注册便可实现集群
- 启动方法 php server.php gateway start [-d]

# 如果对你有帮助，欢迎star
