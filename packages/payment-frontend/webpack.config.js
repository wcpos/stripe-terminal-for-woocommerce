const path = require('path');
const TerserPlugin = require('terser-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = (env, argv) => {
  const isProduction = argv.mode === 'production';

  return {
    entry: './src/payment.js',
    output: {
      path: path.resolve(__dirname, '../../assets'),
      filename: 'js/payment.js',
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
          use: [
            isProduction ? MiniCssExtractPlugin.loader : 'style-loader',
            'css-loader'
          ]
        }
      ]
    },
    plugins: [
      ...(isProduction ? [
        new MiniCssExtractPlugin({
          filename: 'css/payment.css'
        })
      ] : [])
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
