# Node.js environment to run Vite build
FROM node:20-alpine AS frontend-builder
WORKDIR /app

# Copy package configurations and install dependencies
COPY package*.json ./
RUN npm install

# Copy configuration files and source code for Vite
COPY vite.config.* ./
COPY . .

# Run the Vite production build
RUN npm run build

# PHP Apache production environment
FROM php:8.2-apache

# Install system dependencies, zip tools, and PostgreSQL drivers
RUN apt-get update && apt-get install -y \
    libpq-dev \
    git \
    unzip \
    zip \
    && docker-php-ext-install pdo pdo_pgsql curl

# Install Composer inside the container
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Copy the core PHP project files into Apache public directory
COPY . /var/www/html/

# Set working directory context for Composer execution
WORKDIR /var/www/html/

# Run Composer install to generate the vendor/autoload.php file
# --no-dev optimizes the packages for production environment speed
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Copy Vite's compiled production assets into the server
COPY --from=frontend-builder /app/dist /var/www/html/dist

# Open port 80 for web access
EXPOSE 80
CMD ["apache2-foreground"]