<?xml version="1.0" encoding="UTF-8"?>
<xsl:stylesheet
	version="1.0"
	xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	xmlns:saxon="http://icl.com/saxon"
>

	<!-- we need to apply templates to sub-elements, just in case someone wrapped a native quiote element and processed that with xsl, for example -->
	<!-- so we cannot use copy-of here -->
	<!-- node() and the copy will mean that everything is copied, even text nodes etc -->
	<xsl:template match="node()|@*">
		<xsl:copy>
			<xsl:apply-templates select="node()|@*"/>
		</xsl:copy>
	</xsl:template>

</xsl:stylesheet>
