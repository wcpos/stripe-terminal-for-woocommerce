const path = require('path');
const fs = require('fs');
const TerserPlugin = require('terser-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

// Records the content-hashed filenames so includes/Assets.php can enqueue
// them. Written as {"js/payment.js": "js/payment.<hash>.js", ...}.
class AssetManifestPlugin {
  apply(compiler) {
    compiler.hooks.done.tap('AssetManifestPlugin', (stats) => {
      // A failed production build emits nothing; leave the manifest alone
      // rather than pointing it at files that were never written.
      if (stats.hasErrors()) return;
      const manifest = {};
      for (const file of stats.compilation.entrypoints.get('main').getFiles()) {
        manifest[file.replace(/\.[0-9a-f]{8}\.(js|css)$/, '.$1')] = file;
      }
      fs.writeFileSync(
        path.join(compiler.options.output.path, 'manifest.json'),
        JSON.stringify(manifest, null, 2) + '\n'
      );
    });
  }
}

module.exports = (env, argv) => {
  const isProduction = argv.mode === 'production';

  return {
    entry: './src/payment.js',
    output: {
      path: path.resolve(__dirname, '../../assets'),
      // Content-hashed so a plugin update changes the URL itself. Caches that
      // drop the `?ver=` query string (optimiser plugins, CDNs, the WCPOS
      // desktop webview) kept serving the previous release's payment.js
      // against the current PHP.
      filename: 'js/payment.[contenthash:8].js',
      // Drop the previous build's hashed files; everything else in assets/
      // (blocks build, hand-written files) belongs to someone else.
      clean: { keep: (asset) => !/^(js|css)\/payment\./.test(asset) },
      library: {
        name: 'StripeTerminalPayment',
        type: 'umd',
        export: 'default'
      },
      globalObject: 'this'
    },
    externals: {
      jquery: {
        commonjs: 'jquery',
        commonjs2: 'jquery',
        amd: 'jquery',
        root: '$'
      }
    },
    module: {
      rules: [
        {
          test: /\.css$/i,
          use: [MiniCssExtractPlugin.loader, 'css-loader']
        }
      ]
    },
    plugins: [
      new MiniCssExtractPlugin({
        filename: 'css/payment.[contenthash:8].css'
      }),
      new AssetManifestPlugin()
    ],
    optimization: {
      minimize: isProduction,
      minimizer: [
        new TerserPlugin({
          terserOptions: {
            compress: {
              drop_console: isProduction
            }
          }
        })
      ]
    },
    devtool: isProduction ? false : 'source-map',
    resolve: {
      fallback: {
        "stream": false,
        "crypto": false
      }
    }
  };
};
