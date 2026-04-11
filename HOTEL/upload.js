const { S3Storage } = require("coze-coding-dev-sdk");
const fs = require("fs");
const path = require("path");

async function uploadAndGenerateUrl() {
  const storage = new S3Storage({
    endpointUrl: process.env.COZE_BUCKET_ENDPOINT_URL,
    accessKey: "",
    secretKey: "",
    bucketName: process.env.COZE_BUCKET_NAME,
    region: "cn-beijing",
  });

  // 读取压缩包
  const filePath = path.join(__dirname, "public", "hotel-admin.tar.gz");
  const fileContent = fs.readFileSync(filePath);

  console.log("正在上传文件...");

  // 上传文件
  const key = await storage.uploadFile({
    fileContent: fileContent,
    fileName: "hotel-admin.tar.gz",
    contentType: "application/gzip",
  });

  console.log("上传成功，key:", key);

  // 生成下载链接（有效期7天）
  const downloadUrl = await storage.generatePresignedUrl({
    key: key,
    expireTime: 7 * 24 * 60 * 60, // 7天
  });

  console.log("\n下载链接：");
  console.log(downloadUrl);
}

uploadAndGenerateUrl().catch(console.error);
