<?php

namespace App\Enums;

/**
 * media_renditions.rendition_type 허용값 — DB Table Spec §8.
 */
enum RenditionType: string
{
    case Master = 'MASTER';
    case ProxyVideo = 'PROXY_VIDEO';
    case ProxyImage = 'PROXY_IMAGE';
    case ProxyAudio = 'PROXY_AUDIO';
    case Thumbnail = 'THUMBNAIL';
    case Catalog = 'CATALOG';
    case PagePreview = 'PAGE_PREVIEW';
    case DocumentPreview = 'DOCUMENT_PREVIEW';
    case Waveform = 'WAVEFORM';
    case OcrText = 'OCR_TEXT';
    case ExtractedText = 'EXTRACTED_TEXT';
}
