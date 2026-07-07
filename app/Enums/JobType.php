<?php

namespace App\Enums;

/**
 * 워크플로우 작업 유형 — Job Type Definition v1.0 §2 (13종) + 선택 작업(WAVEFORM·STT·AI_ANALYSIS).
 */
enum JobType: string
{
    case Tm = 'TM';
    case Verify = 'VERIFY';
    case Ma = 'MA';
    case Tc = 'TC';
    case ImageTc = 'IMAGE_TC';
    case AudioTc = 'AUDIO_TC';
    case Ca = 'CA';
    case DocPreview = 'DOC_PREVIEW';
    case Ocr = 'OCR';
    case TextExtract = 'TEXT_EXTRACT';
    case Index = 'INDEX';
    case Publish = 'PUBLISH';
    case Cleanup = 'CLEANUP';
    case Waveform = 'WAVEFORM';
    case Stt = 'STT';
    case AiAnalysis = 'AI_ANALYSIS';
}
