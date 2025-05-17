FROM php:8.2-cli

# نصب curl برای درخواست‌های HTTP
RUN apt-get update && apt-get install -y libcurl4-openssl-dev curl zip unzip

# تنظیم پوشه کاری
WORKDIR /app

# کپی کل پروژه به داخل کانتینر
COPY . .

# اجرای فایل PHP به صورت وب‌سرور
CMD ["php", "-S", "0.0.0.0:10000", "bot.php"]
