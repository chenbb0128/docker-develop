webpack-dev-server监听文件变化，需要对devServer进行如下配置：
```json
  devServer: {
    // hot: true,
    host: '0.0.0.0',
    port: '8888'
  },
  watch: true,
  watchOptions: {
    ignored: /node_modules/,
    aggregateTimeout: 300,
    poll: 500
  },
```