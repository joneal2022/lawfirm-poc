"""
OCR Service with Tesseract 5 Integration
Legal and medical document processing optimized
"""

import os
import asyncio
import tempfile
import time
from typing import Dict, List, Optional, Tuple, Any
from pathlib import Path
import logging

import pytesseract
from PIL import Image, ImageEnhance, ImageFilter
import cv2
import numpy as np
from pdf2image import convert_from_path

from config.settings import settings

logger = logging.getLogger(__name__)


class OCRProcessor:
    """
    Advanced OCR processor with legal/medical document optimization
    """
    
    def __init__(self):
        # Configure Tesseract path (auto-detect on macOS)
        if settings.tesseract_path:
            pytesseract.pytesseract.tesseract_cmd = settings.tesseract_path
        else:
            # Common macOS paths
            possible_paths = [
                '/usr/local/bin/tesseract',
                '/opt/homebrew/bin/tesseract',
                '/usr/bin/tesseract'
            ]
            
            for path in possible_paths:
                if os.path.exists(path):
                    pytesseract.pytesseract.tesseract_cmd = path
                    break
        
        # OCR configuration for legal documents
        self.legal_config = r'--oem 3 --psm 6 -c tessedit_char_whitelist=0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz.,;:!?()-[]{}/"\'@#$%^&*+=<>|`~_'
        
        # OCR configuration for medical documents (includes medical symbols)
        self.medical_config = r'--oem 3 --psm 6'
        
        # Standard configuration
        self.standard_config = r'--oem 3 --psm 6'
    
    def preprocess_image(self, image: Image.Image, document_type: str = "standard") -> Image.Image:
        """
        Preprocess image for better OCR accuracy
        """
        try:
            # Convert to RGB if necessary
            if image.mode != 'RGB':
                image = image.convert('RGB')
            
            # Convert to numpy array for OpenCV processing
            img_array = np.array(image)
            img_gray = cv2.cvtColor(img_array, cv2.COLOR_RGB2GRAY)
            
            # Apply different preprocessing based on document type
            if document_type == "medical":
                # Medical documents often have forms and tables
                img_gray = cv2.medianBlur(img_gray, 3)  # Reduce noise
                img_gray = cv2.adaptiveThreshold(
                    img_gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C, cv2.THRESH_BINARY, 11, 2
                )
            elif document_type == "legal":
                # Legal documents often have fine text
                img_gray = cv2.bilateralFilter(img_gray, 9, 75, 75)  # Preserve edges
                _, img_gray = cv2.threshold(img_gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
            else:
                # Standard preprocessing
                img_gray = cv2.GaussianBlur(img_gray, (1, 1), 0)
                _, img_gray = cv2.threshold(img_gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
            
            # Convert back to PIL Image
            processed_image = Image.fromarray(img_gray)
            
            # Enhance contrast and sharpness
            enhancer = ImageEnhance.Contrast(processed_image)
            processed_image = enhancer.enhance(1.2)
            
            enhancer = ImageEnhance.Sharpness(processed_image)
            processed_image = enhancer.enhance(1.1)
            
            return processed_image
            
        except Exception as e:
            logger.error(f"Image preprocessing failed: {e}")
            return image  # Return original if preprocessing fails
    
    def extract_text_from_image(
        self, 
        image: Image.Image, 
        document_type: str = "standard",
        confidence_threshold: int = 30
    ) -> Dict[str, Any]:
        """
        Extract text from a single image with confidence scoring
        """
        try:
            # Preprocess image
            processed_image = self.preprocess_image(image, document_type)
            
            # Select appropriate OCR configuration
            if document_type == "medical":
                config = self.medical_config
            elif document_type == "legal":
                config = self.legal_config
            else:
                config = self.standard_config
            
            # Extract text with confidence data
            ocr_data = pytesseract.image_to_data(
                processed_image,
                config=config,
                output_type=pytesseract.Output.DICT
            )
            
            # Extract plain text
            text = pytesseract.image_to_string(processed_image, config=config)
            
            # Calculate confidence metrics
            confidences = [int(conf) for conf in ocr_data['conf'] if int(conf) > 0]
            avg_confidence = sum(confidences) / len(confidences) if confidences else 0
            
            # Filter words by confidence threshold
            high_confidence_words = []
            for i, conf in enumerate(ocr_data['conf']):
                if int(conf) >= confidence_threshold:
                    word = ocr_data['text'][i].strip()
                    if word:
                        high_confidence_words.append({
                            'text': word,
                            'confidence': int(conf),
                            'bbox': {
                                'left': ocr_data['left'][i],
                                'top': ocr_data['top'][i],
                                'width': ocr_data['width'][i],
                                'height': ocr_data['height'][i]
                            }
                        })
            
            return {
                'text': text.strip(),
                'confidence': avg_confidence,
                'word_count': len(text.split()),
                'char_count': len(text),
                'high_confidence_words': high_confidence_words,
                'processing_quality': 'high' if avg_confidence > 80 else 'medium' if avg_confidence > 60 else 'low'
            }
            
        except Exception as e:
            logger.error(f"OCR text extraction failed: {e}")
            return {
                'text': '',
                'confidence': 0,
                'word_count': 0,
                'char_count': 0,
                'high_confidence_words': [],
                'processing_quality': 'failed',
                'error': str(e)
            }
    
    def convert_pdf_to_images(self, pdf_path: str, dpi: int = 300) -> List[Image.Image]:
        """
        Convert PDF pages to images for OCR processing
        """
        try:
            images = convert_from_path(
                pdf_path,
                dpi=dpi,
                first_page=1,
                last_page=None,
                fmt='jpeg',
                thread_count=4
            )
            return images
        except Exception as e:
            logger.error(f"PDF conversion failed: {e}")
            return []
    
    async def process_document(
        self,
        file_path: str,
        document_type: str = "standard",
        document_id: Optional[int] = None
    ) -> Dict[str, Any]:
        """
        Main document processing method
        Handles both images and PDFs
        """
        start_time = time.time()
        
        try:
            file_path = Path(file_path)
            
            if not file_path.exists():
                raise FileNotFoundError(f"File not found: {file_path}")
            
            # Determine file type and process accordingly
            file_extension = file_path.suffix.lower()
            
            if file_extension == '.pdf':
                # Process PDF
                pages_data = await self._process_pdf(str(file_path), document_type)
                
                # Combine all pages
                combined_text = "\n\n".join([page['text'] for page in pages_data])
                avg_confidence = sum([page['confidence'] for page in pages_data]) / len(pages_data) if pages_data else 0
                total_words = sum([page['word_count'] for page in pages_data])
                
                result = {
                    'document_id': document_id,
                    'file_path': str(file_path),
                    'document_type': document_type,
                    'pages': len(pages_data),
                    'combined_text': combined_text,
                    'average_confidence': avg_confidence,
                    'total_words': total_words,
                    'total_chars': len(combined_text),
                    'pages_data': pages_data,
                    'processing_time': time.time() - start_time,
                    'status': 'completed'
                }
                
            elif file_extension in ['.jpg', '.jpeg', '.png', '.tiff', '.bmp']:
                # Process single image
                image = Image.open(file_path)
                ocr_result = self.extract_text_from_image(image, document_type)
                
                result = {
                    'document_id': document_id,
                    'file_path': str(file_path),
                    'document_type': document_type,
                    'pages': 1,
                    'combined_text': ocr_result['text'],
                    'average_confidence': ocr_result['confidence'],
                    'total_words': ocr_result['word_count'],
                    'total_chars': ocr_result['char_count'],
                    'pages_data': [ocr_result],
                    'processing_time': time.time() - start_time,
                    'status': 'completed'
                }
                
            else:
                raise ValueError(f"Unsupported file type: {file_extension}")
            
            # Add quality assessment
            result['quality_assessment'] = self._assess_ocr_quality(result)
            
            return result
            
        except Exception as e:
            logger.error(f"Document processing failed: {e}")
            return {
                'document_id': document_id,
                'file_path': str(file_path) if 'file_path' in locals() else '',
                'document_type': document_type,
                'status': 'failed',
                'error': str(e),
                'processing_time': time.time() - start_time
            }
    
    async def _process_pdf(self, pdf_path: str, document_type: str) -> List[Dict[str, Any]]:
        """Process PDF document page by page"""
        try:
            # Convert PDF to images
            images = await asyncio.to_thread(self.convert_pdf_to_images, pdf_path)
            
            if not images:
                raise ValueError("Failed to convert PDF to images")
            
            # Process each page
            pages_data = []
            for page_num, image in enumerate(images, 1):
                logger.info(f"Processing page {page_num} of {len(images)}")
                
                ocr_result = self.extract_text_from_image(image, document_type)
                ocr_result['page_number'] = page_num
                pages_data.append(ocr_result)
            
            return pages_data
            
        except Exception as e:
            logger.error(f"PDF processing failed: {e}")
            return []
    
    def _assess_ocr_quality(self, ocr_result: Dict[str, Any]) -> Dict[str, str]:
        """Assess the quality of OCR results and provide recommendations"""
        avg_confidence = ocr_result.get('average_confidence', 0)
        total_words = ocr_result.get('total_words', 0)
        
        if avg_confidence >= 85 and total_words > 50:
            return {
                'overall': 'excellent',
                'recommendation': 'No manual review needed',
                'reliability': 'high'
            }
        elif avg_confidence >= 70 and total_words > 20:
            return {
                'overall': 'good',
                'recommendation': 'Spot check recommended',
                'reliability': 'medium-high'
            }
        elif avg_confidence >= 50 and total_words > 10:
            return {
                'overall': 'fair',
                'recommendation': 'Manual review recommended',
                'reliability': 'medium'
            }
        else:
            return {
                'overall': 'poor',
                'recommendation': 'Full manual review required',
                'reliability': 'low'
            }
    
    def create_searchable_pdf(self, original_pdf_path: str, ocr_result: Dict[str, Any]) -> Optional[str]:
        """Create a searchable PDF with OCR text overlay (placeholder for future implementation)"""
        # This would integrate with PDF libraries to create searchable PDFs
        # For POC, we'll just return the original path
        logger.info("Searchable PDF creation not yet implemented")
        return original_pdf_path


# Global OCR processor instance
ocr_processor = OCRProcessor()