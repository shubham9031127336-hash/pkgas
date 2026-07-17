<?php
require_once __DIR__ . '/lang_init.php';
require_once 'auth.php';
require_once 'csrf.php';
require_once 'db.php';
require_once 'compression-utils.php';
require_login();
validateCsrfToken();

$id = $_GET['id'] ?? null;
$post = null;

$page_title = $id ? __('blog_editor.edit_title') : __('blog_editor.create_title');
$active_menu = 'blog';

if ($id) {
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE id = ?");
    $stmt->execute([$id]);
    $post = $stmt->fetch();
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $category = $_POST['category'] ?? '';
    $excerpt = $_POST['excerpt'] ?? '';
    $content = $_POST['content'] ?? '';
    $meta_title = $_POST['meta_title'] ?? '';
    $meta_description = $_POST['meta_description'] ?? '';
    $meta_keywords = $_POST['meta_keywords'] ?? '';
    
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $title)));
    
    $image_name = $post['image'] ?? '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
        $target_dir = "../uploads/blog/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);

        $tmp_file = $_FILES["image"]["tmp_name"];
        $new_name = time() . '_' . uniqid();
        $target_path = $target_dir . $new_name . '.webp';

        $compression = compressImage($tmp_file, $target_path, 75);

        if (!$compression['success'] && isset($compression['fallback'])) {
            $image_ext = pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION);
            $target_path = $target_dir . $new_name . '.' . $image_ext;
            $compression = compressImageFallback($tmp_file, $target_path);
        }

        if ($compression['success']) {
            $image_name = $compression['format'] === 'webp' ? $new_name . '.webp' : $new_name . '.' . pathinfo($_FILES["image"]["name"], PATHINFO_EXTENSION);
        }
    }

    try {
        if ($id) {
            $stmt = $pdo->prepare("UPDATE posts SET title = ?, slug = ?, excerpt = ?, content = ?, image = ?, category = ?, meta_title = ?, meta_description = ?, meta_keywords = ? WHERE id = ?");
            $stmt->execute([$title, $slug, $excerpt, $content, $image_name, $category, $meta_title, $meta_description, $meta_keywords, $id]);
            $message = __('blog_editor.msg_updated');
        } else {
            $stmt = $pdo->prepare("INSERT INTO posts (title, slug, excerpt, content, image, category, meta_title, meta_description, meta_keywords) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $slug, $excerpt, $content, $image_name, $category, $meta_title, $meta_description, $meta_keywords]);
            $message = __('blog_editor.msg_created');
            header("Location: dashboard.php");
            exit();
        }
    } catch (PDOException $e) {
        $message = __('blog_editor.msg_error') . ' ' . $e->getMessage();
    }
}

require_once 'layout.php';
?>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/compressorjs/1.2.1/compressor.min.js"></script>
<link rel="stylesheet" href="blog-editor.css">

<div class="blog-editor-form">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;">
        <div>
            <h1 style="font-size:1.75rem;font-weight:800;letter-spacing:-0.02em;"><?php echo $page_title; ?></h1>
            <p style="color:var(--admin-muted);margin-top:0.25rem;font-size:0.9rem;"><?php echo __('blog_editor.subtitle'); ?></p>
        </div>
    </div>
    
    <?php if ($message): ?>
        <?php $is_err = strpos($message, __('blog_editor.msg_error')) !== false; ?>
        <div style="background: <?php echo $is_err ? '#fef2f2' : '#ecfdf5'; ?>; color: <?php echo $is_err ? '#991b1b' : '#065f46'; ?>; padding: 1.25rem; border-radius: 16px; margin-bottom: 2rem; border: 1px solid <?php echo $is_err ? '#fecaca' : '#a7f3d0'; ?>; display: flex; align-items: center; gap: 1rem;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

        <form method="POST" id="postForm" enctype="multipart/form-data" style="display: grid; gap: 2rem;"><?php csrfField(); ?>
            <input type="hidden" name="content" id="contentInput">
            
            <div style="display: grid; grid-template-columns: 1.5fr 1fr; gap: 2rem;">
                <div class="form-group">
                    <label><?php echo __('blog_editor.post_title'); ?></label>
                    <input type="text" name="title" class="form-control" placeholder="<?php echo __('blog_editor.title_placeholder'); ?>" value="<?php echo htmlspecialchars($post['title'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label><?php echo __('blog_editor.category'); ?></label>
                    <select name="category" class="form-control">
                        <option value="Industrial" <?php echo ($post['category'] ?? '') == 'Industrial' ? 'selected' : ''; ?>><?php echo __('blog_editor.cat_industrial'); ?></option>
                        <option value="Medical" <?php echo ($post['category'] ?? '') == 'Medical' ? 'selected' : ''; ?>><?php echo __('blog_editor.cat_medical'); ?></option>
                        <option value="News" <?php echo ($post['category'] ?? '') == 'News' ? 'selected' : ''; ?>><?php echo __('blog_editor.cat_news'); ?></option>
                        <option value="Safety" <?php echo ($post['category'] ?? '') == 'Safety' ? 'selected' : ''; ?>><?php echo __('blog_editor.cat_safety'); ?></option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label><?php echo __('blog_editor.featured_image'); ?></label>
                <div style="display: flex; align-items: center; gap: 2rem; background: #f8fafc; padding: 1.5rem; border-radius: 16px; border: 1px dashed #cbd5e1;">
                    <div id="imagePreviewContainer">
                        <?php if ($post['image'] ?? ''): ?>
                            <img id="imagePreview" src="../uploads/blog/<?php echo $post['image']; ?>" style="width: 120px; height: 80px; object-fit: cover; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);">
                        <?php else: ?>
                            <div id="imagePlaceholder" style="width: 120px; height: 80px; background: #e2e8f0; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: #94a3b8;">
                                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h7m4 0h6m-3-3v6M6.75 15l2.25-3 1.5 2 2.25-3 3 4h-9z"/></svg>
                            </div>
                            <img id="imagePreview" style="width: 120px; height: 80px; object-fit: cover; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); display: none;">
                        <?php endif; ?>
                    </div>
                    <div>
                        <div id="compressionBadge" class="compression-badge"><?php echo __('blog_editor.compressed_optimized'); ?></div>
                        <input type="file" id="imageInput" name="image" style="font-size: 0.9rem;">
                        <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 0.5rem;"><?php echo __('blog_editor.image_recommend'); ?></p>
                        <p id="compressionInfo" style="font-size: 0.75rem; color: var(--admin-accent); margin-top: 0.25rem; font-weight: 600;"></p>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label><?php echo __('blog_editor.excerpt'); ?></label>
                <textarea name="excerpt" class="form-control" rows="2" placeholder="<?php echo __('blog_editor.excerpt_placeholder'); ?>"><?php echo htmlspecialchars($post['excerpt'] ?? ''); ?></textarea>
            </div>

            <div style="background: #fffbeb; padding: 1.5rem; border-radius: 24px; border: 1px solid #fef3c7; display: grid; gap: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; color: #92400e; font-size: 1.1rem;"><?php echo __('blog_editor.seo_heading'); ?></h3>
                    <button type="button" id="btnAiGenerate" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border: none; padding: 0.5rem 1rem; border-radius: 12px; font-weight: 700; cursor: pointer; display: flex; align-items: center; gap: 0.5rem; transition: transform 0.2s;">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg>
                        <?php echo __('blog_editor.ai_generate'); ?>
                    </button>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem;">
                    <div class="form-group">
                        <label style="color: #92400e;"><?php echo __('blog_editor.seo_title'); ?></label>
                        <input type="text" name="meta_title" class="form-control" placeholder="<?php echo __('blog_editor.seo_title_placeholder'); ?>" value="<?php echo htmlspecialchars($post['meta_title'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label style="color: #92400e;"><?php echo __('blog_editor.seo_description'); ?></label>
                        <textarea id="meta_description" name="meta_description" class="form-control" rows="1" placeholder="<?php echo __('blog_editor.seo_description_placeholder'); ?>"><?php echo htmlspecialchars($post['meta_description'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="form-group">
                    <label style="color: #92400e;"><?php echo __('blog_editor.seo_keywords'); ?></label>
                    <div id="tag-container" style="display: flex; flex-wrap: wrap; gap: 0.5rem; padding: 0.75rem; border: 1px solid #e2e8f0; border-radius: 16px; background: white; min-height: 56px; align-items: center;">
                        <input type="text" id="tag-input" placeholder="<?php echo __('blog_editor.keywords_placeholder'); ?>" style="border: none; outline: none; flex: 1; padding: 0.5rem; font-size: 0.95rem; background: transparent;">
                    </div>
                    <input type="hidden" name="meta_keywords" id="meta_keywords_hidden" value="<?php echo htmlspecialchars($post['meta_keywords'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label><?php echo __('blog_editor.detailed_content'); ?></label>
                <div id="editor"><?php echo $post['content'] ?? ''; ?></div>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 2rem;">
                <button type="submit" class="btn-primary" style="padding: 1.25rem 3rem; font-size: 1.1rem; border-radius: 16px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem;"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                    <?php echo $id ? __('blog_editor.update_btn') : __('blog_editor.publish_btn'); ?>
                </button>
                <a href="dashboard.php" class="btn-admin btn-edit" style="text-decoration: none; padding: 1.25rem 2rem; border-radius: 16px; display: flex; align-items: center;"><?php echo __('blog_editor.cancel_btn'); ?></a>
            </div>
        </form>
    </div>

<script>
    const tagContainer = document.getElementById('tag-container');
    const tagInput = document.getElementById('tag-input');
    const hiddenInput = document.getElementById('meta_keywords_hidden');
    let tags = hiddenInput.value ? hiddenInput.value.split(',').map(t => t.trim()).filter(t => t !== "") : [];

    function renderTags() {
        document.querySelectorAll('.tag').forEach(t => t.remove());
        tags.forEach((tag, index) => {
            const tagEl = document.createElement('div');
            tagEl.classList.add('tag');
            tagEl.innerHTML = `${tag}<span onclick="removeTag(${index})">&times;</span>`;
            tagContainer.insertBefore(tagEl, tagInput);
        });
        hiddenInput.value = tags.join(', ');
    }

    function addTag(text) {
        const cleanTags = text.split(',').map(t => t.trim()).filter(t => t !== "" && !tags.includes(t));
        tags = [...tags, ...cleanTags];
        renderTags();
        tagInput.value = '';
    }

    function removeTag(index) {
        tags.splice(index, 1);
        renderTags();
    }

    tagInput.addEventListener('keydown', (e) => {
        if (e.key === ',' || e.key === 'Enter') {
            e.preventDefault();
            addTag(tagInput.value);
        }
    });

    tagInput.addEventListener('blur', () => {
        if (tagInput.value) addTag(tagInput.value);
    });

    tagInput.addEventListener('paste', (e) => {
        e.preventDefault();
        const text = e.clipboardData.getData('text');
        addTag(text);
    });

    renderTags();

    const imageInput = document.getElementById('imageInput');
    const imagePreview = document.getElementById('imagePreview');
    const imagePlaceholder = document.getElementById('imagePlaceholder');
    const imagePreviewContainer = document.getElementById('imagePreviewContainer');
    const compressionBadge = document.getElementById('compressionBadge');
    const compressionInfo = document.getElementById('compressionInfo');

    imageInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = (event) => {
            imagePreview.src = event.target.result;
            imagePreview.style.display = 'block';
            if (imagePlaceholder) imagePlaceholder.style.display = 'none';
        };
        reader.readAsDataURL(file);

        imagePreviewContainer.classList.add('compressing');
        compressionBadge.style.display = 'none';
        compressionInfo.innerText = '';

        new Compressor(file, {
            quality: 0.75,
            maxWidth: 1600,
            mimeType: 'image/webp',
            success(result) {
                imagePreviewContainer.classList.remove('compressing');
                compressionBadge.style.display = 'inline-block';
                compressionBadge.innerText = 'WebP Optimized';
                compressionBadge.style.background = '#10b981';
                
                const savedKB = ((file.size - result.size) / 1024).toFixed(1);
                const percent = Math.max(0, Math.round(((file.size - result.size) / file.size) * 100));
                const finalSize = (result.size / 1024).toFixed(1);
                
                compressionInfo.innerHTML = `WebP Saved: <span style="color: #059669;">${finalSize} KB</span> (-${percent}%)`;

                const compressedFile = new File([result], file.name.replace(/\.[^/.]+$/, "") + ".webp", {
                    type: 'image/webp',
                    lastModified: Date.now(),
                });

                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(compressedFile);
                imageInput.files = dataTransfer.files;
            },
            error(err) {
                console.error(err.message);
                imagePreviewContainer.classList.remove('compressing');
            },
        });
    });

    var BaseImageFormat = Quill.import('formats/image');
    class CustomImage extends BaseImageFormat {
        static formats(domNode) {
            const formats = super.formats ? super.formats(domNode) : {};
            const allowedAttributes = ['title', 'data-original-size', 'data-compressed-size', 'data-saved-kb', 'data-saved-percent', 'style'];
            allowedAttributes.forEach(attr => {
                if (domNode.hasAttribute(attr)) {
                    formats[attr] = domNode.getAttribute(attr);
                }
            });
            return formats;
        }
        format(name, value) {
            const allowedAttributes = ['title', 'data-original-size', 'data-compressed-size', 'data-saved-kb', 'data-saved-percent', 'style'];
            if (allowedAttributes.includes(name)) {
                if (value) {
                    this.domNode.setAttribute(name, value);
                } else {
                    this.domNode.removeAttribute(name);
                }
            } else {
                super.format(name, value);
            }
        }
    }
    Quill.register(CustomImage, true);

    var quill = new Quill('#editor', {
        theme: 'snow',
        modules: {
            toolbar: [
                [{ 'header': [1, 2, 3, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'color': [] }, { 'background': [] }],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['link', 'image', 'video'],
                ['clean']
            ]
        }
    });

    const toolbar = quill.getModule('toolbar');
    toolbar.addHandler('image', function() {
        const input = document.createElement('input');
        input.setAttribute('type', 'file');
        input.setAttribute('accept', 'image/*');
        input.click();

        input.onchange = function() {
            const file = input.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('file', file);
            var csrfToken = document.querySelector('input[name="_csrf_token"]');
            if (csrfToken) formData.append('_csrf_token', csrfToken.value);

            fetch('upload.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const blotIndex = quill.getSelection().index;
                    quill.insertEmbed(blotIndex, 'image', data.url, 'user');

                    if (data.isCompressed) {
                        quill.formatText(blotIndex, 1, {
                            'data-original-size': data.originalSize,
                            'data-compressed-size': data.compressedSize,
                            'data-saved-kb': data.savedKB,
                            'data-saved-percent': data.savedPercent,
                            'title': `WebP Optimized\nBefore: ${(data.originalSize / 1024).toFixed(1)}KB\nAfter: ${(data.compressedSize / 1024).toFixed(1)}KB\nSaved: ${data.savedKB}KB (-${data.savedPercent}%)`,
                            'style': 'cursor: help;'
                        }, 'user');
                    }
                } else {
                    alert('<?php echo __('blog_editor.alert_upload_failed'); ?>' + data.message);
                }
            })
            .catch(err => {
                console.error('Upload error:', err);
                alert('<?php echo __('blog_editor.alert_upload_error'); ?>');
            });
        };
    });

    toolbar.addHandler('video', function() {
        const input = document.createElement('input');
        input.setAttribute('type', 'file');
        input.setAttribute('accept', 'video/*');
        input.click();

        input.onchange = function() {
            const file = input.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('file', file);
            var csrfToken = document.querySelector('input[name="_csrf_token"]');
            if (csrfToken) formData.append('_csrf_token', csrfToken.value);

            fetch('upload.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const blotIndex = quill.getSelection().index;
                    quill.insertEmbed(blotIndex, 'video', data.url, 'user');
                } else {
                    alert('<?php echo __('blog_editor.alert_video_failed'); ?>' + data.message);
                }
            })
            .catch(err => {
                console.error('Upload error:', err);
                alert('<?php echo __('blog_editor.alert_upload_error'); ?>');
            });
        };
    });

    quill.root.addEventListener('mouseover', function(e) {
        if (e.target.tagName === 'IMG' && e.target.hasAttribute('data-saved-kb')) {
            e.target.style.backgroundColor = 'rgba(16, 185, 129, 0.1)';
            e.target.style.borderRadius = '4px';
            e.target.style.padding = '2px';
        }
    });

    quill.root.addEventListener('mouseout', function(e) {
        if (e.target.tagName === 'IMG') {
            e.target.style.backgroundColor = '';
            e.target.style.borderRadius = '';
            e.target.style.padding = '';
        }
    });

    var form = document.getElementById('postForm');
    form.onsubmit = function() {
        var content = document.querySelector('input[name=content]');
        content.value = quill.root.innerHTML;
    };

    const btnAiGenerate = document.getElementById('btnAiGenerate');
    const metaDescInput = document.getElementById('meta_description');

    if (btnAiGenerate) {
        btnAiGenerate.addEventListener('click', function() {
            const title = document.querySelector('input[name="title"]').value;
            const contentText = quill.getText();

            if (!title && !contentText.trim()) {
                alert('<?php echo __('blog_editor.alert_ai_required'); ?>');
                return;
            }

            const originalText = btnAiGenerate.innerHTML;
            btnAiGenerate.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg> <?php echo __('blog_editor.generating'); ?>';
            btnAiGenerate.disabled = true;

            fetch('ai-generate-meta.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ title: title, content: contentText })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    metaDescInput.value = data.meta_description;
                    
                    if (data.meta_keywords) {
                        const newTags = data.meta_keywords.split(',').map(t => t.trim());
                        newTags.forEach(t => addTag(t));
                    }

                    metaDescInput.style.transition = 'all 0.5s';
                    metaDescInput.style.backgroundColor = '#d1fae5';
                    tagContainer.style.transition = 'all 0.5s';
                    tagContainer.style.backgroundColor = '#d1fae5';

                    setTimeout(() => {
                        metaDescInput.style.backgroundColor = '';
                        tagContainer.style.backgroundColor = 'white';
                    }, 1000);
                } else {
                    alert('<?php echo __('blog_editor.alert_upload_failed'); ?>' + data.message);
                }
            })
            .catch(err => {
                console.error('AI error:', err);
                alert('<?php echo __('blog_editor.alert_ai_error'); ?>');
            })
            .finally(() => {
                btnAiGenerate.innerHTML = originalText;
                btnAiGenerate.disabled = false;
            });
        });
    }
</script>

<?php require_once 'layout_footer.php'; ?>
